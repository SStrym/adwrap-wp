<?php
/**
 * Plugin Name: SVG Support
 * Description: Enable SVG uploads in WordPress
 */

// Allow SVG uploads
add_filter('upload_mimes', function ($mimes) {
    $mimes['svg'] = 'image/svg+xml';
    $mimes['svgz'] = 'image/svg+xml';
    return $mimes;
});

// Fix SVG display in Media Library
add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes) {
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    
    if ($ext === 'svg') {
        $data['type'] = 'image/svg+xml';
        $data['ext'] = 'svg';
        $data['proper_filename'] = $filename;
    }
    
    return $data;
}, 10, 4);

// Add SVG support for thumbnails in admin
add_action('admin_head', function () {
    echo '<style>
        .attachment-266x266, .thumbnail img[src$=".svg"] {
            width: 100% !important;
            height: auto !important;
        }
    </style>';
});

