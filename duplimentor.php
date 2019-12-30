<?php

/*
 *
 * Plugin Name: Duplimentor
 *
 * Author : Mark Dicker
 * Description:  Create a duplicate of elementor pages and templates
 * Version: 0.0.1
 *
 * @package         duplimentor
 * 
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0
 */

/**
 *	Copyright (C) 2019-2119 Mark Dicker (email: mark@markdicker.co.uk)
 *
 *	This program is free software; you can redistribute it and/or
 *	modify it under the terms of the GNU General Public License
 *	as published by the Free Software Foundation; either version 2
 *	of the License, or (at your option) any later version.
 *
 *	This program is distributed in the hope that it will be useful,
 *	but WITHOUT ANY WARRANTY; without even the implied warranty of
 *	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *	GNU General Public License for more details.
 *
 *	You should have received a copy of the GNU General Public License
 *	along with this program; if not, write to the Free Software
 *	Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

// if ( ! class_exists( 'Duplimentor_CLI' ) ) :

// define( 'DUPLIMENTOR_PLUGIN_DIR' , dirname( __FILE__ ) );

class Duplimentor_CLI
{

    private $post_types = array (
            'post',
            'elementor_library',
            // 'attachment',
            // 'nav_menu_item',
            'page',        
        );

    private $timestamp;

    // function __invoke( $args )
    // {
    //     WP_CLI::success( $args[0] );
    // }

    function __construct()
    {
        $this->timestamp = time();        
    }    

    /**
     * Export pages and images 
     *
     * ## EXAMPLES
     *
     *     wp duplimentor export
     *
     * @when after_wp_load
     */
    public function export( $args )
    {
        global $wpdb;

        $upload_dir = wp_upload_dir();
        
        // WP_CLI::line( "Working in ".getcwd().PHP_EOL );

        // WP_CLI::line( "<pre>".print_r( $upload_dir, true )."</pre>".PHP_EOL );

        $export_dir = 'duplimentor-'.$this->timestamp;
        if (! is_dir($export_dir)) {
            mkdir( $export_dir, 0755 );
        }
    
        $json = array();

        $post_types = apply_filters( "duplimentor_process_post_types", $this->post_types );

        $posts = $wpdb->get_results( 
                $wpdb->prepare( "select * from `".$wpdb->prefix."posts` where post_type in ( ".implode( ",", array_fill( 0, count( $post_types ), '%s' ) )." )", $post_types )
            );    

        // Master list of all images used on pages

        $post_images = array();

        WP_CLI::line( "Exporting pages" );

        foreach( $posts  as $post )    
        {            
            $terms = $wpdb->get_results( 
                $wpdb->prepare( "select * from `".$wpdb->prefix."postmeta` where post_id = %d", $post->ID )
            );             

            $media = get_attached_media( '', $post->ID ); // Get image attachment(s) to the current Post

            foreach( $media as $m )
            {
                $post_images[ $m->ID ] = $m->ID;
            }

            // WP_CLI::line( print_r( $media, true ) );

            // WP_CLI::line( $post->ID." -> ".$post->post_type." -> ".print_r( $media, true ) );

            if ( trim ( $post->post_content ) !== "" )
            {
                // Get list of images used in a post
                
                $document = new DOMDocument();
                @$document->loadHTML($post->post_content);
                
                $images = $document->getElementsByTagName('img');
                
                if ( !empty( $images ) ) 
                {
                    foreach ( $images as $img )
                    {
                        // WP_CLI::line( print_r( $img, true ) ) ;
                        foreach( $img->attributes as $att )
                        {
                            switch( $att->name ) 
                            {
                                case 'class':
                                    $c = explode( " ", $att->nodeValue );
                                    
                                    foreach ( $c as $cp )
                                    {
                                        if ( self::startsWith( $cp, "wp-image-" ) !== false )
                                        {
                                            $id = trim( substr( $cp, 9 ) );

                                            $post_images [ $id ] = $id;
                                            
                                            // WP_CLI::line( "id = ".$id ) ;
                                        }
                                    }
                                    
                                break;
                            }
                            // WP_CLI::line( "".$img->attributes['src']->value );
                            // WP_CLI::line( "".$img->attributes['srcset']->value );
                        }
                    }
                    // WP_CLI::line( $post->ID." -> ".$post->post_type." -> ".print_r( $images, true ) );

                    foreach( $terms as $term )
                    {
                        // $term->meta_value = str_replace( $upload_dir['basedir'], "<<DUPLIMENTOR>>", $term->meta_value );

                        // WP_CLI::line( $term->meta_value );

                        if ( $term->meta_key == '_elementor_data' )
                        {
                            // get any images used by elementor

                            $ed = json_decode( $term->meta_value );

                            $ed_ids = $this->scan_ed( $ed );

                            // array_merge( $post_images, $ed_ids );

                            foreach( $ed_ids as $id )
                            {
                                if ( !isset( $post_images[ $id ] ) )
                                {
                                    $post_images[ $id ] = $id;
                                }
                            }

                            break;  // We don't need to scan any more as there will only be 1 _elementor_data per post
                        }
                    }
                }              
                
                $json[] = array( 'p' => $post, 't' => $terms );
                
            }
            
        }    
        
        // WP_CLI::line( print_r( $post_images, true ) );              
        
        // add the images to the end of the posts array

        WP_CLI::line( "Exporting images" );

        $attachments = $wpdb->get_results( 
            $wpdb->prepare( "select * from `".$wpdb->prefix."posts` where ID in ( ".implode( ",", $post_images )." )", "") 
        );    

        foreach ( $attachments as $attachment )
        {
            $terms = $wpdb->get_results( 
                $wpdb->prepare( "select * from `".$wpdb->prefix."postmeta` where post_id = %d", $attachment->ID )
            );             

            
            // copy file from uploads folder into export folder.  Keep same URL structure
            
            $src = get_attached_file( $attachment->ID );
            $dest = getcwd().'/'.$export_dir.str_replace( $upload_dir['basedir'], "/uploads", get_attached_file( $attachment->ID ) );
            
            $json[] = array( 'p' => $attachment, 't' => $terms, 'u' => str_replace( $upload_dir['basedir'], "/uploads", get_attached_file( $attachment->ID ) ) );
            
            // WP_CLI::line( "Copying ". $src." to ".$dest );
            
            $this->createFolder( $dest );
            copy( $src, $dest );
        }
        
        //echo print_r( $json );

        file_put_contents( $export_dir.'/config.json', json_encode( array (
            'url' => site_url(),
            'uploads_url' => $upload_dir['baseurl'],
            'uploads_dir' => $upload_dir['basedir'],
        ) ) );

        file_put_contents( $export_dir.'/duplimentor.json', json_encode( $json ) );

        // zip up the folder

        $this->createArchive( $export_dir.".zip", $export_dir );

        // delete the folder

        $this->deleteFolder( $export_dir."/" );

    }

    private function createFolder( $path )
    {

        $paths = explode( "/", $path );

        $filename = array_pop( $paths );

        $final_path = '';

        foreach ( $paths as $path )
        {

            if ( !is_dir( $final_path . '/' . $path ) )
            {
                //echo "<pre>".$final_path . '/' . $path."</pre>";

                // write_log( $final_path );
                mkdir( $final_path. '/'. $path, 0755, true );

                chmod( $final_path . '/' . $path, 0755 );  // -RWX-R-X-R-X-

            }

            $final_path .= '/' . $path;

        }

        return $final_path;

    }

    private function scan_ed( $ed, $ed_array = array() )
    {
        $ed_a = $ed_array;

        $upload_dir = wp_upload_dir();

        foreach ( $ed as $node )
        {
            if ( !empty( $node->settings ) )
            {
                foreach ( $node->settings as $name => $setting )
                {
                    if ( strlen( $name ) >= 5 )
                    {                        
                        if ( self::endsWith( $name, "image" ) || self::endsWith( $name, "image_mobile" ) || self::endsWith( $name, "image_tablet" ) )
                        {
                            if ( $setting->id !== "" )
                            {
                                $ed_a[ $setting->id ] = $setting->id;
                            }
                        }
                    }
                }                        
            }

            // foreach ( $node->elements as $iNode )
            // {
            //     // if ( !empty( $iNode->settings ) )
            //     // {
            //     //     foreach ( $iNode->settings as $name => $setting )
            //     //     {
            //     //         WP_CLI::line( $name. " ".strlen( $name ) ) ;
            //     //         if ( strlen( $name ) >= 5 )
            //     //         {                            
            //     //             if ( self::endsWith( $name, "image" ) || self::endsWith( $name, "image_mobile" ) || self::endsWith( $name, "image_tablet" ) )
            //     //             {
            //     //                 if ( $setting->id !== "" )
            //     //                 {
            //     //                     // WP_CLI::line( $name." -> ".print_r( $setting, true ) );
            //     //                     WP_CLI::line( $name." -> ".$setting->url );

            //     //                     $ed_a[ $setting->id ] = $setting->id;
            //     //                 }
            //     //             }
            //     //         }
            //     //     }                        
            //     // }

            //     // Drill down into branches
            // }
            $ed_a = self::scan_ed( $node->elements, $ed_a );
        }

        return $ed_a;
    }

    private function rename_ed_images( $ed, $page_map, $image_map )
    {

        // WP_CLI::line( "page_map ".print_r( $page_map, true ) );
        // WP_CLI::line( "image_map ".print_r( $image_map, true ) );
        
        foreach ( $ed as $node )
        {
            // WP_CLI::line( print_r( $node, true ) ) ;

            if ( !empty( $node->settings ) )
            {
                foreach ( $node->settings as $name => $setting )
                {
                    if ( strlen( $name ) >= 5 )
                    {
                        if ( self::endsWith( $name, "image" ) || self::endsWith( $name, "image_mobile" ) || self::endsWith( $name, "image_tablet" ) )
                        {
                            if ( isset( $setting->id) && $setting->id !== "" )
                            {
                                // WP_CLI::line( $name." -> ".print_r( $setting, true ) );

                                $setting->id = $page_map[ $setting->id ];
                                $setting->url = $image_map[ $setting->id ];

                                // WP_CLI::line( $name." -> ".print_r( $setting, true ) );

                            }
                        }
                    }
                }                        
            }

            // foreach ( $node->elements as $iNode )
            // {   
            $node->elements = $this->rename_ed_images( $node->elements, $page_map, $image_map );
            // }
        }

        // WP_CLI::line( print_r( $ed, true ) );

        return $ed; // TODO : Fix
    }


    private static function folderToZip($folder, &$zipFile) { 
        $handle = opendir($folder); 
        while (false !== $f = readdir($handle)) { 
          if ($f != '.' && $f != '..') { 
            $filePath = "$folder/$f"; 
            // Remove prefix from file path before add to zip. 
            $localPath = $filePath;

            if (is_file($filePath)) 
            { 
              $zipFile->addFile($filePath, $localPath); 
            } 
            elseif (is_dir($filePath)) 
            { 
              // Add sub-directory. 
              $zipFile->addEmptyDir($localPath); 
              self::folderToZip($filePath, $zipFile); 
            } 
          } 
        } 
        closedir($handle); 
    } 

    private function createArchive( $file, $folder )
    {
        // exec( "zip ".$file." ".$folder );

        $pathInfo = pathInfo($folder); 
        $parentPath = $pathInfo['dirname']; 
        $dirName = $pathInfo['basename']; 

        $z = new ZipArchive(); 
        $z->open($file, ZIPARCHIVE::CREATE); 
        // $z->addEmptyDir($dirName); 
        self::folderToZip($folder, $z ); 
        $z->close(); 

    }

    private function deleteFolder( $folder )
    {
        $dir = $folder;
        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it,
                    RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file) 
        {
            if ($file->isDir())
            {
                rmdir($file->getRealPath());
                // WP_CLI::line( "Remove ".$file->getRealPath() );

            } 
            else 
            {
                unlink($file->getRealPath());
                // WP_CLI::line( "Remove ".$file->getRealPath() );
            }
        }
        // WP_CLI::line( "Remove ".$dir );

        rmdir($dir);
    }

    /**
     * import pages and images 
     *
     * ## EXAMPLES
     *
     *     wp duplimentor import <archive>
     *
     * @when after_wp_load
     */
    public function import( $args )
    {
        global $wpdb;

        
        if ( count( $args ) < 1 )
        {
            WP_CLI::error( "Not enough arguments" );
            exit(0);
        }
        
        if ( strpos( $args[0], ".zip", -4 ) === false )
        {
            WP_CLI::error( "Use must pass an archive" );
            exit(0);
        }
        
        $upload_dir = wp_upload_dir();

        $data_dir = substr( $args[0],0, -4 );

        // Unzip the folder
        $z = new ZipArchive( ); 
        $z->open( $args[0] ); 
        $z->extractTo( "." );
        $z->close(); 

        $config = json_decode( file_get_contents( $data_dir."/config.json" ) );

        $json = json_decode( file_get_contents( $data_dir."/duplimentor.json" ) );

        $page_map = array( 0 => 0 );    // Store old wp_post ID to new wp_post ID mapping
        $image_map = array();   // Quick look up table for new new image url by its new ID

        // Add all pages first

        WP_CLI::line( "Importing pages" );

        foreach ( $json as $entry )
        {
            // See if the page already exists

            // if it does             
            //     update the page with the new contents
            // else
            //     create a new page
            

            if ( $entry->p->post_type == 'attachment' )
            {
                // copy the file to the correct place in the media library

                $filename = $data_dir. $entry->u;

                // Check the type of file. We'll use this as the 'post_mime_type'.
                $filetype = wp_check_filetype( basename( $filename ), null );
                
                $dest = str_replace( "/uploads", $upload_dir['basedir'], $entry->u );
                $dest_url = str_replace( "/uploads", $upload_dir['baseurl'], $entry->u );
                
                // WP_CLI::line( $filename." => ".$dest_url );

                $img_title = preg_replace( '/\.[^.]+$/', '', basename( $filename ) );

                $img = get_page_by_path( $img_title, OBJECT, $entry->p->post_type );

                if ( $img == null )
                {
                    self::createFolder( $dest );
                    copy( $filename, $dest );

                    // Prepare an array of post data for the attachment.
                    $attachment = array(
                        'guid'           => $dest_url, 
                        'post_mime_type' => $filetype['type'],
                        'post_title'     => $img_title,
                        'post_content'   => '',
                        'post_status'    => 'inherit'
                        
                    );

                    // // Insert the attachment.
                    $attach_id = wp_insert_attachment( $attachment, $dest, $page_map[ $entry->p->post_parent ] );

                    $image_map[ $attach_id ] = $dest_url;
                    $page_map[ $entry->p->ID ] = $attach_id;

                    // Make sure that this file is included, as   wp_generate_attachment_metadata() depends on it.
                    require_once( ABSPATH . 'wp-admin/includes/image.php' );

                    // Generate the metadata for the attachment, and update the database record.
                    $attach_data = wp_generate_attachment_metadata( $attach_id, $dest );
                    wp_update_attachment_metadata( $attach_id, $attach_data );

                    // Add any terms for this media object

                    foreach ( $entry->t as $term )
                    {
                        if ( $term->meta_key != '_wp_attachment_data' && $term->meta_key != '_wp_attached_file' )
                        {
    
                        }
                        else
                        {
                            if ( $term->meta_key == '_elementor_data' )
                            {
                                // get any images used by elementor

                                // $ed = json_decode( $term->meta_value );

                                // $ed_ids = $this->rename_ed_images( $ed );

                                // // array_merge( $post_images, $ed_ids );

                                // foreach( $ed_ids as $id )
                                // {
                                //     if ( !isset( $post_images[ $id ] ) )
                                //     {
                                //         $post_images[ $id ] = $id;
                                //     }
                                // }

                                // break;  // We don't need to scan any more as there will only be 1 _elementor_data per post
                            }

                            update_post_meta( $page_map[ $terms->post_id ], $term->meta_key, $term->meta_value );
                        }
                    }
                }
                else
                {
                    $image_map[ $img->ID ] = $dest_url;
                    $page_map[ $entry->p->ID ] = $img->ID;
                }
            }
            else
            {
                $post = get_page_by_path( $entry->p->post_name, OBJECT, $entry->p->post_type );

                if ( $post != null )
                {
                    $page_map[ $entry->p->ID ] = $post->ID;

                    $entry->p->ID = $post->ID;

                    $id = $post->ID;

                    wp_update_post( $entry->p );
                }
                else
                {
                    $old_id = $entry->p->ID;
                    $entry->p->ID = 0;

                    unset( $entry->p->ID );

                    $id = wp_insert_post( $entry->p, true );
                    
                    if ( is_wp_error ( $id ) )
                    {
                        WP_CLI::error( $id->get_error_message );
                    }

                    $page_map[ $old_id ] = $id;

                }


            }                
        }
        
        // WP_CLI::line( print_r( $page_map, true ) ) ;

        // Now add all terms

        WP_CLI::line( "Importing meta data" );

        foreach ( $json as $entry )
        {
            // We don't need to process attachments as they have already been processed.

            if ( $entry->p->post_type != 'attachment' )
            {
                foreach ( $entry->t as $term )
                {
                    if ( is_serialized( $term->meta_value ) )
                        $meta_value = unserialize( $term->meta_value );
                    else
                        $meta_value = $term->meta_value;

                    if ( $term->meta_key == '_elementor_data' )
                    {
                        // fix any images used by elementor

                        $ed = json_decode( $term->meta_value );

                        $ed = $this->rename_ed_images( $ed, $page_map, $image_map );

                        // WP_CLI::line( "compare ".strpos( json_encode( $ed ), "portraits" ) ); 
                        
                        // WP_CLI::line( print_r( $ed, true ) );
                        
                        $meta_value = json_encode( $ed );

                        // WP_CLI::line( $meta_value );
                        
                        // break;  // We don't need to scan any more as there will only be 1 _elementor_data per post
                    }

                    update_post_meta( $page_map[ $term->post_id ], $term->meta_key, $meta_value );
                }
            }
        }

        // Relink the post parents

        foreach ( $json as $entry )
        {
            // if ( $entry->p->post_parent > 0 )
            // {

                WP_CLI::line( $entry->p->ID ." -> ". $page_map[ $entry->p->ID ] ." | ". $entry->p->post_parent ." -> ". $page_map[ $entry->p->post_parent ] . ' | ' . $entry->p->post_type . ' -> '.$entry->p->post_title );

                wp_update_post(
                    array(
                        'ID' => $page_map[ $entry->p->ID ],
                        'post_parent' => $page_map[ $entry->p->post_parent ]
                    )
                );            
            // }
        }

    }
    
    private function endsWith($haystack, $needle)
    {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }
    
        return (substr($haystack, -$length) === $needle);
    }

    private function startsWith($haystack, $needle)
    {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }
    
        return (substr($haystack, 0, $length) === $needle);
    }


}
WP_CLI::add_command( 'duplimentor', 'Duplimentor_CLI' );

// endif;
