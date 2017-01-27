<?php
use Mediawiki\Api\ApiUser;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\MediawikiFactory;
use Mediawiki\DataModel\EditInfo;
use Mediawiki\DataModel\Page;
use Mediawiki\DataModel\Revision;

define('BASE_WIKI', 'mediawikiwiki');
define('TEMPLATE', 'Q28226172');
$wikidata_api_url = 'https://www.wikidata.org/w/api.php';

require_once 'vendor/autoload.php';

$http = new GuzzleHttp\Client();

$wikis = getWikidataLinks(TEMPLATE);
$templateWikis = reset($wikis);

if (empty($templateWikis[BASE_WIKI])) {
    die("Base wiki template not defined");
}

$baseTemplate = $templateWikis[BASE_WIKI];
unset($templateWikis[BASE_WIKI]);
$baseWikiApi = new WikiApi(BASE_WIKI);
$basePages = $baseWikiApi->getTransclusions($baseTemplate);
// Eliminate /doc pages

$basePages = array_filter($basePages, function ($p) {
    return substr($p, -4, 4) !== '/doc';
});
// FIXME: do it in batches
$ids = getWikidataIds($basePages, BASE_WIKI);

// FIXME: do it in batches
foreach (getWikidataLinks($ids) as $links) {
    if(empty($links[BASE_WIKI])) {
        // No base template
        // TODO: delete them?
        continue;
    }
    $baseContent = $baseWikiApi->loadPageText($links[BASE_WIKI]);
    if(empty($baseContent)) {
        // TODO: should we allow empty ones? Probably not.
        continue;
    }
    unset($links[BASE_WIKI]);
    foreach($links as $wiki => $pageName) {
        $localApi = new WikiApi($wiki);
        copyFromTo($localApi, $pageName, $baseContent);
    }
}

function copyFromTo(WikiApi $toApi, $pageTo, $content)
{
    $oldContent = $toApi->loadPageText($pageTo);
    if($oldContent !== $content) {
        // FIXME: check that the template is present!
        $toApi->savePage($content, "Updating from source wiki");
    } else {
        echo "${pageTo} skipped, no changes\n";
    }
}

function getWikidataLinks($qid)
{
    global $wikidata_api_url, $http;
    if (!is_array($qid)) {
        $qid = [$qid];
    }
    $ids = implode("|", $qid);
    var_dump($ids);
    $url = "$wikidata_api_url?action=wbgetentities&ids=$ids&format=json";
    $res = $http->get($url);
    if($res->getStatusCode() != 200) {
        // FIXME: better error handler
        die($res->getReasonPhrase());
    }
    $jdata = $res->getBody();
    $data = json_decode($jdata, true);
    if(empty($data['entities'])) {
        return [];
    }
    return array_map(
        function ($ent) {
            $links = [];
            foreach ($ent['sitelinks'] as $wiki => $data) {
                $links[$wiki] = $data['title'];
            }
            return $links;
        },
        $data['entities']);
}

function getWikidataIds($titles, $wiki)
{
    global $http;
    $ids = urlencode(implode("|", $titles));
    $wiki = getWebserverForWiki($wiki);
    $url = "https://$wiki/w/api.php?action=query&format=json&prop=pageprops&titles=$ids&ppprop=wikibase_item";
    $res = $http->get($url);
    if($res->getStatusCode() != 200) {
        // FIXME: better error handler
        die($res->getReasonPhrase());
    }
    $jdata = $res->getBody();
    $data = json_decode($jdata, true);
    $ids = [];
    foreach($data['query']['pages'] as $page) {
        if(empty($page['pageprops']['wikibase_item'])) {
            continue;
        }
        $ids[] = $page['pageprops']['wikibase_item'];
    }
    return $ids;
}

function getWikiInstances($title, WikiApi $wiki)
{
    $db = openDB($wiki);
    $title = $db->real_escape_string($title);
    var_dump($title);
    $sql = "select page.* from page,templatelinks t1 where page_id=t1.tl_from and t1.tl_title='$title' and t1.tl_namespace=10";
    $inst = [];
    if (!$result = $db->query($sql)) die('There was an error running the query [' . $db->error . ']');
    while ($o = $result->fetch_object()) {
        $inst[] = $o->page_title;
    }
    return $inst;
}

function openDB($wiki)
{
    global $mysql_user, $mysql_password, $o, $common_db_cache, $use_db_cache;

    $server_parts = explode('.', getWebserverForWiki($wiki));
    // FIXME: error check
    $db_key = "${server_parts[0]}.${server_parts[1]}";
    if (isset ($common_db_cache[$db_key])) return $common_db_cache[$db_key];

    getDBpassword();
    $dbname = getDBname($server_parts[0], $server_parts[1]);

    $server = substr($dbname, 0, -2) . '.labsdb';
    $db = new mysqli($server, $mysql_user, $mysql_password, $dbname);
    if ($db->connect_errno > 0) {
        $o['msg'] = 'Unable to connect to database [' . $db->connect_error . ']';
        $o['status'] = 'ERROR';
        return false;
    }
    if ($use_db_cache) $common_db_cache[$db_key] = $db;
    return $db;
}

function getWebserverForWiki($wiki)
{
    if ($wiki == 'commonswiki') return "commons.wikimedia.org";
    if ($wiki == 'wikidatawiki') return "www.wikidata.org";
    if ($wiki == 'specieswiki') return "species.wikimedia.org";
    if ($wiki == 'mediawikiwiki') return "www.mediawiki.org";
    $wiki = preg_replace('/_/', '-', $wiki);
    if (preg_match('/^(.+)wiki$/', $wiki, $m)) return $m[1] . ".wikipedia.org";
    if (preg_match('/^(.+)(wik.+)$/', $wiki, $m)) return $m[1] . "." . $m[2] . ".org";
    return '';
}

function getDBname($language, $project)
{
    $ret = $language;
    if ($language == 'commons') $ret = 'commonswiki_p';
    elseif ($language == 'wikidata' || $project == 'wikidata') $ret = 'wikidatawiki_p';
    elseif ($language == 'mediawiki' || $project == 'mediawiki') $ret = 'mediawikiwiki_p';
    elseif ($language == 'meta' && $project == 'wikimedia') $ret = 'metawiki_p';
    elseif ($project == 'wikipedia') $ret .= 'wiki_p';
    elseif ($project == 'wikisource') $ret .= 'wikisource_p';
    elseif ($project == 'wiktionary') $ret .= 'wiktionary_p';
    elseif ($project == 'wikibooks') $ret .= 'wikibooks_p';
    elseif ($project == 'wikinews') $ret .= 'wikinews_p';
    elseif ($project == 'wikiversity') $ret .= 'wikiversity_p';
    elseif ($project == 'wikivoyage') $ret .= 'wikivoyage_p';
    elseif ($project == 'wikiquote') $ret .= 'wikiquote_p';
    elseif ($project == 'wikispecies') $ret = 'specieswiki_p';
    elseif ($language == 'meta') $ret .= 'metawiki_p';
    else if ($project == 'wikimedia') $ret .= $language . $project . "_p";
    else die ("Cannot construct database name for $language.$project - aborting.");
    return $ret;
}

function getDBpassword()
{
    global $mysql_user, $mysql_password;
    $passwordfile = $_ENV['HOME'] . '/replica.my.cnf';
    $config = parse_ini_file($passwordfile);
    if (isset($config['user'])) {
        $mysql_user = $config['user'];
    }
    if (isset($config['password'])) {
        $mysql_password = $config['password'];
    }
}

class WikiApi
{
    private $wiki_server;
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

    public function __construct($wiki)
    {
        $this->wiki_server = getWebserverForWiki($wiki);
    }

    protected function initMWApi()
    {
        if (empty($this->w)) {
            $this->w = new MediawikiApi('https://' . $this->wiki_server . '/w/api.php');
        }
        if (!$this->w->isLoggedin()) {
            $ini = parse_ini_file('bot.ini');
            $this->wiki_user = $ini['user'];
            $this->wiki_pass = $ini['pass'];
            $x = $this->w->login(new ApiUser($this->wiki_user, $this->wiki_pass));
            if (!$x) {
                $this->error = "Bot login failed for {$this->wiki_server}: {$this->wiki_user}, {$this->wiki_pass}";
                return false;
            }
        }
        $this->services = new MediawikiFactory($this->w);

        return true;
    }

    public function loadPageText($page)
    {
        $page = str_replace(' ', '_', $page);

        if (!$this->initMWApi()) {
            return false;
        }

        $this->pageHandle = $this->services->newPageGetter()->getFromTitle($page);
        $this->pageRevision = $this->pageHandle->getRevisions()->getLatest();
        if (!isset($this->pageRevision) or !is_object($this->pageRevision)) {
            $this->error = "Could not get page";
            return false;
        }

        return $this->pageRevision->getContent()->getData();
    }

    public function savePage($content, $summary)
    {
        $editInfo = new EditInfo ($summary, EditInfo::MINOR, EditInfo::BOT);
        $contentObject = new Mediawiki\DataModel\Content ($content);
        $revision = new Mediawiki\DataModel\Revision ($contentObject, $this->pageRevision->getPageIdentifier(), null, $editInfo);

        try {
            $this->services->newRevisionSaver()->save($revision, $editInfo);
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }

        return true;
    }

    public function getTransclusions($template)
    {
        if (!$this->initMWApi()) {
            return false;
        }
        $pageListGetter = $this->services->newPageListGetter();
        return array_map(function (Page $p) {
            return $p->getPageIdentifier()->getTitle()->getText();
        }, $pageListGetter->getPageListFromPageTransclusions($template)->toArray());

    }
}

