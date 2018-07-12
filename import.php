<?php

require 'vendor/autoload.php';

// This is to prevent bad char substitions by Parsedown.
ini_set( 'default_charset', 'ISO-8859-1' );

global $dd, $keyword_post, $all_attrs;

$pd = new Parsedown;
$dd = new DOMDocument;
$dir = new DirectoryIterator( 'keywords' );

$output_file = fopen( 'inserted_posts.json', 'w' );
$output = [];

$attr_output_file = fopen( 'parsed_attributes.csv', 'w' );

$permissions_csv_file = fopen( 'Digital Pedagogy Permissions - MLA (May 2018) - Master List.csv', 'r' );

$not_keywords = [
    '.DS_Store',
    'blueBox.md',
    '!template.md',
    '!template-skeleton.md',
];

$keyword_count = 0;
$artifact_count = 0;

$all_attrs = [ [
    'type',
    'title',
    'label',
    'value',
    'parsed?',
] ];

foreach ( $dir as $fileinfo ) {
    if (
        $fileinfo->isDot() ||
        in_array( $fileinfo->getFilename(), $not_keywords ) ||
        ( isset( $args[0] ) && false === strpos( $fileinfo->getFilename(), $args[0] ) ) // optional filter for debugging
    ) {
        continue;
    }

    // tons of invalid markup errors...
    @$dd->loadHTML( mb_convert_encoding(
        $pd->text( file_get_contents( $fileinfo->getPathname() ) ),
        'HTML-ENTITIES',
        'UTF-8'
    ) );

    // import keyword
    $keyword_post = [];
    parse_keyword_nodes( $dd, $keyword_post );
    $keyword_count++;
    $keyword_post_id = wp_insert_post( $keyword_post );
    $keyword_post['ID'] = $keyword_post_id;
    WP_CLI::log( $fileinfo->getFilename() . ": keyword '${keyword_post['post_title']}' ($keyword_post_id)" );

    // import artifacts
    $artifact_posts = [];
    parse_artifact_nodes( $dd, $artifact_posts );
    foreach ( $artifact_posts as $artifact_post ) {
        $artifact_count++;
        $artifact_post_id = wp_insert_post( $artifact_post );
        $artifact_post['ID'] = $artifact_post_id;
        WP_CLI::log( $fileinfo->getFilename() . ": artifact '${artifact_post['post_title']}' ($artifact_post_id)" );
    }

    $output[] = [
        $keyword_post,
        $artifact_posts,
    ];

}

fwrite( $output_file, json_encode( $output ) );

foreach ( $all_attrs as $attr ) {
    fputcsv( $attr_output_file, $attr );
}


WP_CLI::success( "imported $keyword_count keywords & $artifact_count artifacts" );

function set_post_attr( &$post, $label, $value ) {
    global $all_attrs;

    $all_attrs[] = [
        $post['post_type'],
        $post['post_title'],
        $label,
        $value,
    ];

    switch ( $label ) {
        // junk(?)
    case 'publication status':
        break;

        // title
    case 'chapter':
    case 'title of artifact':
        if ( ! isset( $post['post_title'] ) ) {
            $post['post_title'] = $value;
        }
        break;

        // tags
    case 'tags':
    case 'category':
    case 'cross-reference keywords':
        if ( 1 < count( explode( ',', $value ) ) ) {
            $post['tags_input'] = array_map( 'trim', explode( ',', $value ) );
        } else if ( 1 < count( explode( ';', $value ) ) ) {
            $post['tags_input'] = array_map( 'trim', explode( ';', $value ) );
        } else {
            $post['tags_input'] = $value;
        }
        break;

        // image
    case 'screenshot':
    case 'image':
        $post['meta_input']['image'] = $value;
        break;

        // url
    case 'http':
    case 'https':
        $post['meta_input']['url'] = "$label:$value";
        break;
    case 'source':
    case 'source url':
    case 'url':
    case 'artifact copy':
    case 'copy of artifact':
    case 'copy of the artifact':
    case 'downloadable resource':
        $post['meta_input']['url'] = $value;
        break;

        // license
    case 'artifact permissions':
    case 'permission':
    case 'permissions':
    case 'license':
        $post['meta_input']['license'] = $value;
        break;

        // creators
    case 'creator':
    case 'co-creator':
    case 'creators':
    case 'co-creators':
    case 'creator affiliation':
    case 'creator and affiliation':
    case 'creators and affiliation':
    case 'creators and affiliations':
        $post['meta_input']['creator(s)'] = $value;
        break;

        // type
    case 'type':
    case 'artifact type':
    case 'type of artifact':
        $post['meta_input']['type'] = $value;
        break;

        // various meta
    case 'title':
    case 'subtitle':
    case 'publisher':
        $post['meta_input'][ $label ] = $value;
        break;

        // authors & editors each have "family" & "given"
        // these always appear in order, so just prepend to existing value
    case 'author':
    case 'editor':
        $post['meta_input'][ $label ] = '';
        break;
    case 'family':
    case 'given':
        if ( isset( $post['meta_input']['editor'] ) ) {
            $post['meta_input']['editor'] = trim( $value . ' ' . $post['meta_input']['editor'] );
        }
        if ( isset( $post['meta_input']['author'] ) ) {
            $post['meta_input']['author'] = trim( $value . ' ' . $post['meta_input']['author'] );

            // We have a complete author name, now get the ID.
            if ( 'given' === $label && empty( $post['post_author'] ) ) {
                $post['post_author'] = get_author_id( $post['meta_input']['author'] );
            }
        }
        break;
    default:
        // Ignore post attributes like title/content.
        $ignore = false;
        array_walk_recursive( $post, function( $post_attr ) use ( $value ) {
            if ( ! empty( $value ) && false !== strpos( $post_attr, $value ) ) {
                $ignore = true;
            }
        } );

        if ( ! $ignore ) {
            array_push( $all_attrs[ count( $all_attrs ) - 1 ], 'could not parse!' );
            WP_CLI::warning( "could not parse ${post['post_type']} attribute: '$label: $value'" );
        }
        break;
    }
}

function parse_keyword_nodes( DOMNode $parent, &$post ) {
    global $dd;

    if ( ! isset( $post['post_title'] ) && isset( $parent->getElementsByTagName('h1')[0] ) ) {
        $post['post_title'] = ucfirst( strtolower( $parent->getElementsByTagName('h1')[0]->nodeValue ) );
    }
    if ( ! isset( $post['post_status'] ) ) {
        $post['post_status'] = 'publish';
    }
    if ( ! isset( $post['post_type'] ) ) {
        $post['post_type'] = 'digiped_keyword';
    }
    if ( ! isset( $post['post_content'] ) ) {
        $post['post_content'] = $dd->saveHTML();
    }

    foreach ( $parent->childNodes as $node ) {
        if( $node->hasChildNodes() ) {
            parse_keyword_nodes( $node, $post );
        } else {
            if ( in_array( trim( strtolower( $node->nodeValue ) ), [ 'curated artifacts', 'reflection' ] ) ) {
                // done with this keyword, on to artifacts
                if ( ! isset( $post['post_author'] ) ) {
                    $post['post_author'] = 0;
                }
                break;
            }

            $exp = explode( ':', $node->nodeValue );

            if ( 2 !== count( $exp ) ) {
                continue; // this doesn't look like a key/value pair, move on.
            }

            // at this point we have a probable attribute, figure out what it is & add to $post
            $label = strtolower( $exp[0] );
            $value = trim( $exp[1] );

            set_post_attr( $post, $label, $value );
        }
    }
}

function parse_artifact_nodes( DOMNode $parent, &$posts ) {
    global $keyword_post;

    // if we see any of these headers, we're done with artifacts in this keyword
    $breakers = [
        'related materials',
        'related works',
        'work cited',
        'works cited',
    ];

    foreach ( $parent->childNodes as $node ) {
        if( $node->hasChildNodes() ) {
            parse_artifact_nodes( $node, $posts );
        } else {
            // skip empty nodes
            if ( empty( trim( $node->nodeValue ) ) ) {
                continue;
            }

            // are we in the artifacts section yet?
            if ( ! isset( $posts['_found_artifacts'] ) ) {
                if ( 'curated artifacts' === trim( strtolower( $node->nodeValue ) ) ) {
                    $posts['_found_artifacts'] = 0;
                }
                continue;
            }

            // done with all artifacts in this keyword?
            if ( in_array( trim( strtolower( $node->nodeValue ) ), $breakers ) ) {
                foreach ( $posts as $i => $post ) {
                    if ( ! isset( $post['post_title'] ) ) {
                        unset( $posts[ $i ] );
                    }
                }
                break;
            }

            // have we reached a new artifact yet?
            if ( in_array( $node->parentNode->nodeName, ['h2', 'h3', 'h4', 'h5', 'h6'] ) ) {
                if ( 1 === preg_match( '/part [\d]:/', strtolower( $node->nodeValue ) ) ) {
                    // these are section headers, not artifacts
                    continue;
                }

                // an artifact!
                $posts['_found_artifacts']++;
                $posts[ count( $posts ) ] = [
                    'post_title' => $node->nodeValue,
                    'post_status' => 'publish',
                    'post_type' => 'digiped_artifact',
                    'post_author' => $keyword_post['post_author'],
                    'post_parent' => $keyword_post['ID'],
                    'meta_input' => [
                        'keyword' => $keyword_post['post_title'],
                    ],
                    'tags_input' => [],
                ];
                continue;
            }

            // is this a link? assume screenshot
            if ( $node instanceof DOMElement && 'screenshot' === strtolower( $node->nodeValue ) ) {
                var_dump( $node );
                die;
                set_post_attr( $posts[ count( $posts ) ], 'url', $node->getAttribute( 'href' ) );
                continue;
            }

            $exp = explode( ':', $node->nodeValue );

            if ( 2 !== count( $exp ) ) {
                // this isn't a key/value pair, assume normal content.
                $posts[ count( $posts ) - 1 ]['post_content'] = ( isset( $post[ count( $posts ) - 1 ]['post_content'] ) )
                    ? $post[ count( $posts ) - 1 ]['post_content'] .= $node->nodeValue
                    : $node->nodeValue;
                continue;
            }

            // at this point we have a probable attribute, add to $post
            $label = strtolower( $exp[0] );
            $value = trim( $exp[1] );

            set_post_attr( $posts[ count( $posts ) - 1 ], $label, $value );
        }
    }
}

function get_author_id( string $name ) {
    $result = bp_core_get_users( [
        'search_terms' => $name
    ] );

    foreach ( $result['users'] as $user ) {
        if ( $name === $user->display_name ) {
            return $user->ID;
        }
    }

    // search matched, but not exactly. assume first is good.
    if ( 0 < $result['total'] ) {
        return $result['users'][0]->ID;
    }

    // Fallback...who should this be?
    return 1;
}
