<?php

define( 'BASE_WIKI', 'mediawikiwiki' );
define( 'TEMPLATE', 'Q28226172' );
// TODO: load this from Wikidata?
define( 'TEMPLATE_TITLE', 'Template:Mirrored' );
$wikidata_api_url = 'https://www.wikidata.org/w/api.php';

require_once 'vendor/autoload.php';
require_once 'WikiApi.php';

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

