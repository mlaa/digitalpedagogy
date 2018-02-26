<?php

require 'vendor/autoload.php';

$pd = new Parsedown;
$dd = new DOMDocument;
$dir = new DirectoryIterator( 'keywords' );

$keywords_csv = fopen( 'keyword_posts.csv', 'w' );
$artifacts_csv = fopen( 'artifact_posts.csv', 'w' );

foreach ( $dir as $fileinfo ) {
    if ( $fileinfo->isDot() ) {
        continue;
    }

    // tons of invalid markup errors...
    @$dd->loadHTML( $pd->text( file_get_contents( $fileinfo->getPathname() ) ) );

    // import keyword
    $keyword_post = [];
    parse_keyword_nodes( $dd, $keyword_post );
    @fputcsv( $keywords_csv, $keyword_post );
    $keyword_post_id = wp_insert_post( $keyword_post );

    // import artifacts
    $artifact_posts = [];
    parse_artifact_nodes( $dd, $artifact_posts );
    foreach ( $artifact_posts as $artifact_post ) {
        @fputcsv( $artifacts_csv, $artifact_post );
        $artifact_post_id = wp_insert_post( $artifact_post );
    }
}

function parse_keyword_nodes( DOMNode $parent, &$post ) {
    $post['post_status'] = 'publish';
    $post['post_author'] = 5488; // TODO

    foreach ( $parent->childNodes as $node ) {
        if( $node->hasChildNodes() ) {
            parse_keyword_nodes( $node, $post );
        } else {
            if ( in_array( trim( strtolower( $node->nodeValue ) ), [ 'curated artifacts', 'reflection' ] ) ) {
                // done with this keyword, on to artifacts
                break;
            }

            $exp = explode( ':', $node->nodeValue );

            if ( 2 > count( $exp ) ) {
                // this isn't a key/value pair, assume normal content.
                $post['post_content'] = ( isset( $post['post_content'] ) )
                    ? $post['post_content'] .= $node->nodeValue
                    : $node->nodeValue;
                continue;
            }

            if ( 2 < count( $exp ) ) {
                // this might contain several attributes, loop through each
                $lines = explode( PHP_EOL, $node->nodeValue );
                foreach ( $lines as $line ) {
                    // this can cause infinite loops unless we ensure this elem is actually unique
                    if ( $node->nodeValue !== $line ) {
                        $elem = new DOMElement( 'p', htmlentities( $line ) );
                        parse_keyword_nodes( $elem, $post );
                    }
                }
                continue;
            }

            // at this point we have a probable attribute, figure out what it is & add to $post
            $label = strtolower( $exp[0] );
            $value = trim( $exp[1] );

            switch ( $label ) {
                // junk(?)
                case 'publication status':
                    break;

                // title
                case 'chapter':
                    $post['post_title'] = $value;
                    break;

                // tags
                case 'cross-reference keywords':
                    $post['tags_input'] = array_map( 'trim', explode( ',', $value ) );
                    break;

                // url
                case 'http':
                    $post['meta_input']['unlabeled_url'] = $node->nodeValue;
                    break;

                // various meta
                case 'title':
                case 'subtitle':
                case 'url':
                case 'publisher':
                case 'type':
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
                    }
                    break;
            }
        }
    }
}

function parse_artifact_nodes( DOMNode $parent, &$posts ) {
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
            if ( in_array( trim( strtolower( $node->nodeValue ) ), $breakers ) ) {
                // done with all artifacts in this keyword.
                foreach ( $posts as $i => $post ) {
                    if ( ! isset( $post['post_title'] ) ) {
                        unset( $posts[ $i ] );
                    }
                }
                break;
            }

            // are we in the artifacts section yet?
            if ( ! isset( $posts['_found_artifacts'] ) ) {
                if ( 'curated artifacts' === trim( strtolower( $node->nodeValue ) ) ) {
                    $posts['_found_artifacts'] = 0;
                }
                continue;
            }

            if ( empty( trim( $node->nodeValue ) ) ) {
                continue;
            }

            // an artifact!
            if ( 'h2' === $node->parentNode->nodeName ) {
                $posts['_found_artifacts']++;
                $posts[ count( $posts ) ] = [
                    'post_title' => $node->nodeValue,
                    'post_status' => 'publish',
                    'post_type' => 'digiped_artifact',
                    'post_author' => 5488, // TODO
                    'tags_input' => [],
                ];
                continue;
            }

            $exp = explode( ':', $node->nodeValue );

            if ( 2 > count( $exp ) ) {
                // this isn't a key/value pair, assume normal content.
                $posts[ count( $posts ) - 1 ]['post_content'] = ( isset( $post[ count( $posts ) - 1 ]['post_content'] ) )
                    ? $post[ count( $posts ) - 1 ]['post_content'] .= $node->nodeValue
                    : $node->nodeValue;
                continue;
            }

            if ( 2 < count( $exp ) ) {
                // this might contain several attributes, loop through each
                $lines = explode( PHP_EOL, $node->nodeValue );
                foreach ( $lines as $line ) {
                    // this can cause infinite loops unless we ensure this elem is actually unique
                    if ( $node->nodeValue !== $line ) {
                        $elem = new DOMElement( 'p', $line );
                        parse_keyword_nodes( $elem, $posts );
                    }
                }
                continue;
            }

            // at this point we have a probable attribute, figure out what it is & add to $post
            $label = strtolower( $exp[0] );
            $value = trim( $exp[1] );

            switch ( $label ) {
                case "artifact type":
                    $posts[ count( $posts ) - 1 ]['meta_input'][ $label ] = $value;
                    $posts[ count( $posts ) - 1 ]['tags_input'][] = strtolower( $value );
                case "source url":
                case "artifact permissions":
                case "copy of the artifact":
                case "creator and affiliation":
                    break;
            }
        }
    }
}
