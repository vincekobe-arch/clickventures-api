<?php
// Cloudinary credentials
define('CLOUDINARY_CLOUD_NAME', 'dqmqck3re');
define('CLOUDINARY_API_KEY',    '974967598445618');
define('CLOUDINARY_API_SECRET', 'KxJPm8U_1NtjTpBI2FFaOnzFPPY');

function cloudinary_upload($tmp_path, $folder = 'clickventures') {
    $timestamp  = time();
    $params     = "folder={$folder}&timestamp={$timestamp}" . CLOUDINARY_API_SECRET;
    $signature  = sha1($params);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "https://api.cloudinary.com/v1_1/" . CLOUDINARY_CLOUD_NAME . "/auto/upload",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'file'      => new CURLFile($tmp_path),
            'api_key'   => CLOUDINARY_API_KEY,
            'timestamp' => $timestamp,
            'folder'    => $folder,
            'signature' => $signature,
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function cloudinary_delete($public_id) {
    $timestamp = time();
    $params    = "public_id={$public_id}&timestamp={$timestamp}" . CLOUDINARY_API_SECRET;
    $signature = sha1($params);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "https://api.cloudinary.com/v1_1/" . CLOUDINARY_CLOUD_NAME . "/image/destroy",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'public_id' => $public_id,
            'api_key'   => CLOUDINARY_API_KEY,
            'timestamp' => $timestamp,
            'signature' => $signature,
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}
?>