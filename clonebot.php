<?php
use Mediawiki\Api\ApiUser;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\MediawikiFactory;
use Mediawiki\Api\SimpleRequest;
use Mediawiki\DataModel\EditInfo;
use Mediawiki\DataModel\Page;
use Mediawiki\DataModel\Revision;

define( 'BASE_WIKI', 'mediawikiwiki' );
define( 'TEMPLATE', 'Q28226172' );
// TODO: load this from Wikidata?
define( 'TEMPLATE_TITLE', 'Template:Mirrored' );
$wikidata_api_url = 'https://www.wikidata.org/w/api.php';

require_once 'vendor/autoload.php';

$http = new GuzzleHttp\Client();
$baseWikiApi = new WikiApi( BASE_WIKI );

// Load mirror template list on all wikis
$templateWikis = getWikidataLinks( TEMPLATE )[0];

if ( empty( $templateWikis[BASE_WIKI] ) ) {
    die( "Base wiki template not defined" );
}

foreach ( $templateWikis as $wiki => $template ) {
    WikiApi::getForWiki( $wiki )->setTemplate( $template );
}

$baseTemplate = $templateWikis[BASE_WIKI];
// Load pages that use mirror template on source wiki
// TODO: should we limit namespaces we support?
$basePages = $baseWikiApi->getTransclusions( $baseTemplate );
// Eliminate /doc pages
$basePages = array_filter( $basePages, function ( $p ) {
    return substr( $p, -4, 4 ) !== '/doc';
} );
// Convert talk pages to main pages
$mainPages = array_map( function ( $p ) use ( $baseWikiApi ) {
    return $baseWikiApi->getSubjectFromTalk( $p );
}, $basePages );

// FIXME: do it in batches
$ids = getWikidataIds( $basePages, BASE_WIKI );

// Load interwikis for all pages having templates
// FIXME: do it in batches
foreach ( getWikidataLinks( $ids ) as $links ) {
    if ( empty( $links[BASE_WIKI] ) ) {
        // No base template, nothing to do
        continue;
    }
    $baseContent = $baseWikiApi->loadPageText( $links[BASE_WIKI] );
    if ( empty( $baseContent ) ) {
        // TODO: should we allow empty ones? Probably not.
        continue;
    }
    unset( $links[BASE_WIKI] );
    foreach ( $links as $wiki => $pageName ) {
        WikiApi::getForWiki( $wiki )->writeToPage( $pageName, $baseContent );
    }
}

/**
 * Write content to specific page.
 * @param WikiApi $toApi
 * @param $pageTo
 * @param $content
 */
function writeToPage( WikiApi $toApi, $pageTo, $content ) {
    $oldContent = $toApi->loadPageText( $pageTo );
    if ( $oldContent !== $content ) {
        // FIXME: check that the template is present!
        $toApi->savePage( $content, "Updating from source wiki" );
    } else {
        echo "${pageTo} skipped, no changes\n";
    }
}

/**
 * Return instances of a specific template(s) in other wikis.
 * @param string|array $qid Q-ids of the templates in Wikidata
 * @return array array of arrays [wiki => title] for each ID
 */
function getWikidataLinks( $qid ) {
    global $wikidata_api_url, $http;
    if ( !is_array( $qid ) ) {
        $qid = [$qid];
    }
    $ids = implode( "|", $qid );
    $url = "$wikidata_api_url?action=wbgetentities&ids=$ids&format=json";
    $res = $http->get( $url );
    if ( $res->getStatusCode() != 200 ) {
        // FIXME: better error handler
        die( $res->getReasonPhrase() );
    }
    $jdata = $res->getBody();
    $data = json_decode( $jdata, true );
    if ( empty( $data['entities'] ) ) {
        return [];
    }
    return array_map(
        function ( $ent ) {
            $links = [];
            foreach ( $ent['sitelinks'] as $wiki => $data ) {
                $links[$wiki] = $data['title'];
            }
            return $links;
        },
        $data['entities'] );
}

/**
 * Get Wikidata IDs for the set of titles.
 * @param $titles
 * @param $wiki
 * @return array|string
 */
function getWikidataIds( $titles, $wiki ) {
    global $http;
    $ids = urlencode( implode( "|", $titles ) );
    $wiki = getWebserverForWiki( $wiki );
    $url = "https://$wiki/w/api.php?action=query&format=json&prop=pageprops&titles=$ids&ppprop=wikibase_item";
    $res = $http->get( $url );
    if ( $res->getStatusCode() != 200 ) {
        // FIXME: better error handler
        die( $res->getReasonPhrase() );
    }
    $jdata = $res->getBody();
    $data = json_decode( $jdata, true );
    $ids = [];
    foreach ( $data['query']['pages'] as $page ) {
        if ( empty( $page['pageprops']['wikibase_item'] ) ) {
            continue;
        }
        $ids[] = $page['pageprops']['wikibase_item'];
    }
    return $ids;
}

/**
 * Get webserver name for specific wiki
 * @param $wiki
 * @return string
 */
function getWebserverForWiki( $wiki ) {
    if ( $wiki == 'commonswiki' ) return "commons.wikimedia.org";
    if ( $wiki == 'wikidatawiki' ) return "www.wikidata.org";
    if ( $wiki == 'specieswiki' ) return "species.wikimedia.org";
    if ( $wiki == 'mediawikiwiki' ) return "www.mediawiki.org";
    $wiki = preg_replace( '/_/', '-', $wiki );
    if ( preg_match( '/^(.+)wiki$/', $wiki, $m ) ) return $m[1] . ".wikipedia.org";
    if ( preg_match( '/^(.+)(wik.+)$/', $wiki, $m ) ) return $m[1] . "." . $m[2] . ".org";
    return '';
}

class WikiApi
{

    /**
     * @var WikiApi[]
     */
    protected static $perWiki = [];
    /**
     * IDs of namespaces, by name
     * @var int[]
     */
    protected $nsByName;
    /**
     * Names of namespaces, by id
     * @var string[]
     */
    protected $nsById;
    /**
     * Name of the mirror template for this wiki
     * @var string
     */
    protected $templateName;
    /**
     * @var string
     */
    private $wiki_server;
    /**
     * @var MediawikiApi
     */
    private $w;
    private $wiki_user;
    private $wiki_pass;
    /**
     * @var MediawikiFactory
     */
    private $services;
    public $error;
    /**
     * @var Page
     */
    private $pageHandle;
    /**
     * @var Revision
     */
    private $pageRevision;

    public function __construct( $wiki ) {
        $this->wiki_server = getWebserverForWiki( $wiki );
    }

    protected function initMWApi() {
        if ( empty( $this->w ) ) {
            $this->w = new MediawikiApi( 'https://' . $this->wiki_server . '/w/api.php' );
        }
        if ( !$this->w->isLoggedin() ) {
            $ini = parse_ini_file( 'bot.ini' );
            $this->wiki_user = $ini['user'];
            $this->wiki_pass = $ini['pass'];
            $x = $this->w->login( new ApiUser( $this->wiki_user, $this->wiki_pass ) );
            if ( !$x ) {
                $this->error = "Bot login failed for {$this->wiki_server}: {$this->wiki_user}, {$this->wiki_pass}";
                return false;
            }
        }
        $this->services = new MediawikiFactory( $this->w );

        return true;
    }

    public function loadPageText( $page ) {
        $page = str_replace( ' ', '_', $page );

        if ( !$this->initMWApi() ) {
            return false;
        }

        $this->pageHandle = $this->services->newPageGetter()->getFromTitle( $page );
        $this->pageRevision = $this->pageHandle->getRevisions()->getLatest();
        if ( !isset( $this->pageRevision ) or !is_object( $this->pageRevision ) ) {
            $this->error = "Could not get page";
            return false;
        }

        return $this->pageRevision->getContent()->getData();
    }

    public function savePage( $content, $summary ) {
        $editInfo = new EditInfo ( $summary, EditInfo::MINOR, EditInfo::BOT );
        $contentObject = new Mediawiki\DataModel\Content ( $content );
        $revision = new Mediawiki\DataModel\Revision ( $contentObject, $this->pageRevision->getPageIdentifier(), null, $editInfo );

        try {
            $this->services->newRevisionSaver()->save( $revision, $editInfo );
        } catch ( Exception $e ) {
            $this->error = $e->getMessage();
            return false;
        }

        return true;
    }

    /**
     * Get template transclusions
     * @param $template
     * @return array|bool
     */
    public function getTransclusions( $template ) {
        if ( !$this->initMWApi() ) {
            return false;
        }
        $pageListGetter = $this->services->newPageListGetter();
        return array_map( function ( Page $p ) {
            return $p->getPageIdentifier()->getTitle()->getText();
        }, $pageListGetter->getPageListFromPageTransclusions( $template )->toArray() );
    }

    /**
     * Write content to page, if it's not already there but template is there
     * @param $pageTo
     * @param $content
     * @return bool
     */
    public function writeToPage( $pageTo, $content ) {
        if ( !$this->initMWApi() ) {
            return false;
        }
        $oldContent = $this->loadPageText( $pageTo );
        if ( $oldContent !== $content ) {
            // Changed
            if ( !$this->hasTemplate( $pageTo ) ) {
                // Main content doesn't have template, check talk page
                $talkPage = $this->getTalkFromSubject( $pageTo );
                if ( !$talkPage ) {
                    // No talk page and no template
                    return true;
                }
                if ( !$this->hasTemplate( $talkPage ) ) {
                    // Talk page doesn't have it too, skip this one
                    return true;
                }
            }
            $this->savePage( $content, "Updating from source wiki" );
        } else {
            echo "${pageTo} skipped, no changes\n";
        }
        return true;
    }

    /**
     * Check if page has mirror template
     * @param $page
     * @return bool
     */
    public function hasTemplate( $page ) {
        $req = new SimpleRequest(
            'query',
            [
                'titles'      => $page,
                'tltemplates' => $this->templateName,
                'format'      => 'json',
            ]
        );

        $data = $this->w->getRequest( $req );
        if ( empty( $data['query']['pages'] ) ) {
            return false;
        }
        $page = reset( $data['query']['pages'] );
        return !empty( $page['templates'] );
    }

    /**
     * Get WikiApi instance for a specific wiki
     * @param $wiki
     * @return WikiApi
     */
    public static function getForWiki( $wiki ) {
        if ( empty( self::$perWiki[$wiki] ) ) {
            self::$perWiki[$wiki] = new self( $wiki );
        }
        return self::$perWiki[$wiki];
    }

    /**
     * Load wiki namespaces
     * @return int[]
     * @throws Exception
     */
    public function loadNamespaces() {
        if ( !empty( $this->nsByName ) ) {
            return $this->nsByName;
        }
        if ( !$this->initMWApi() ) {
            return [];
        }

        $req = new SimpleRequest(
            'query',
            [
                'meta'          => 'siteinfo',
                'siprop'        => 'namespaces',
                'format'        => 'json',
                'formatversion' => 2
            ]
        );

        $ns = $this->w->getRequest( $req );
        if ( $ns ) {
            foreach ( $ns['query']['namespaces'] as $id => $nsElement ) {
                $this->nsByName[$nsElement['name']] = $id;
                $this->nsByName[$nsElement['canonical']] = $id;
                $this->nsById[$id] = $nsElement['canonical'];
            }
        } else {
            throw new Exception( "Failed to load namespaces" );
        }
        return $this->nsByName;
    }

    /**
     * Check if namespace is talk namespace
     * @param $name
     * @return bool
     * @throws Exception
     */
    protected function isTalkNamespace( $name ) {
        $ns = $this->loadNamespaces();
        if ( !isset( $ns[$name] ) ) {
            throw new Exception( "Unknown namespace $name" );
        }
        $id = $ns[$name];
        return $id > 0 && ($id % 2) != 0;
    }

    /**
     * Get subject NS name from talk NS name
     * @param $name
     * @return string
     */
    protected function getSubjectFromTalkNS( $name ) {
        $ns = $this->loadNamespaces();
        return $this->nsById[$ns[$name] - 1];
    }

    /**
     * Get talk NS name from subject NS name
     * @param $name
     * @return string
     */
    protected function getTalkFromSubjectNS( $name ) {
        $ns = $this->loadNamespaces();
        if ( empty($ns[$name]) || $ns[$name] < 0 ) {
            return null;
        }
        $talkID = $ns[$name] + 1;
        return isset( $this->nsById[$talkID] ) ?
            $this->nsById[$talkID] : null;
    }

    /**
     * Set mirror template name
     * @param $template
     */
    public function setTemplate( $template ) {
        $this->templateName = $template;
    }

    /**
     * Get subject page name from talk page name
     * @param $page
     * @return string
     */
    public function getSubjectFromTalk( $page ) {
        list( $ns, $name ) = explode( ":", $page, 1 );
        if ( empty( $name ) ) {
            // no NS
            return $page;
        }
        if ( !$this->isTalkNamespace( $ns ) ) {
            return $page;
        }
        return $this->getSubjectFromTalkNS( $ns ) . ":" . $name;
    }

    /**
     * Get talk page name from talk page name
     * @param $page
     * @return string Talk page or null if this NS has no talk pages
     */
    public function getTalkFromSubject( $page ) {
        list( $ns, $name ) = explode( ":", $page, 1 );
        if ( empty( $name ) ) {
            // no NS
            return null;
        }
        if ( $this->isTalkNamespace( $ns ) ) {
            return null;
        }
        return $this->getTalkFromSubjectNS( $ns ) . ":" . $name;
    }

}

