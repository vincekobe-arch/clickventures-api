<?php
// Cloudinary credentials
define('CLOUDINARY_CLOUD_NAME', 'dqmqck3re');
define('CLOUDINARY_API_KEY',    '974967598445618');
define('CLOUDINARY_API_SECRET', 'KxJPm8U_1NtjTpBI2FFaOnzFPPY');

function cloudinary_upload($tmp_path, $folder = 'clickventures', $resource_type = 'auto') {
    $timestamp = time();

    // Signature must be built from sorted key=value pairs (excluding file, api_key)
    $sign_params = [
        'folder'    => $folder,
        'timestamp' => $timestamp,
    ];
    ksort($sign_params);
    $sign_string = http_build_query($sign_params) . CLOUDINARY_API_SECRET;
    $signature   = sha1($sign_string);

    // Give HEIC/HEIF files a proper mime type so CURLFile sends them correctly
    $finfo     = finfo_open(FILEINFO_MIME_TYPE);
    $mime      = finfo_file($finfo, $tmp_path);
    finfo_close($finfo);
    $mime_map  = [
        'image/heic' => 'image/heic',
        'image/heif' => 'image/heif',
    ];
    $curl_mime = $mime_map[$mime] ?? $mime;
    $filename  = basename($tmp_path) . '.' . (explode('/', $curl_mime)[1] ?? 'jpg');
    $curl_file = new CURLFile($tmp_path, $curl_mime, $filename);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "https://api.cloudinary.com/v1_1/" . CLOUDINARY_CLOUD_NAME . "/{$resource_type}/upload",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_POSTFIELDS     => [
            'file'      => $curl_file,
            'api_key'   => CLOUDINARY_API_KEY,
            'timestamp' => $timestamp,
            'folder'    => $folder,
            'signature' => $signature,
        ],
    ]);
    $response = curl_exec($ch);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        return ['error' => $curl_err];
    }

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