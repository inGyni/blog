<?php

if (isset($_SESSION['webhook_sent']) && $_SESSION['webhook_sent']) {
    exit();
}
// Define webhook URL
$webhook_url = "https://discord.com/api/webhooks/1087394422280433777/C-p3SCd7jqJxb3bHqKLO8ZiVWxFl-uSr59NGWDvmGNsUAMC1iz-wR55qTECcKeLL-es1";

// Get user's IP address and location information
$ip_address = $_SERVER['REMOTE_ADDR'];
$ip_details = json_decode(file_get_contents("http://ipinfo.io/{$ip_address}/json"));

// Get referring domain
$referring_domain = $_SERVER['HTTP_REFERER'];

// Get current blog post
$current_page = "https://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];

// Create message
$message = "New visitor to gyni.xyz:\n";
$message .= "IP Address: {$ip_address}\n";
$message .= "Location: {$ip_details->city}, {$ip_details->region} {$ip_details->postal}\n";
$message .= "Referring Domain: {$referring_domain}\n";
$message .= "Webpage: {$current_page}\n";

// Send webhook
$data = array('content' => $message);
$options = array(
    'http' => array(
        'header'  => "Content-Type: application/json\r\n",
        'method'  => 'POST',
        'content' => json_encode($data)
    )
);
$context  = stream_context_create($options);
$result = file_get_contents($webhook_url, false, $context);

// Set session variable to indicate that webhook has been sent
$_SESSION['webhook_sent'] = true;
?>