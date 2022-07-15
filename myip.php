<center><h1>
<?php

/**
 * Gets IP address.
 */
function getIpAddress()
{
    $ipAddress = '';
    if (! empty($_SERVER['HTTP_CLIENT_IP'])) {
        // to get shared ISP IP address
        $ipAddress = $_SERVER['HTTP_CLIENT_IP'];
    } else if (! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // check for IPs passing through proxy servers
        // check if multiple IP addresses are set and take the first one
        $ipAddressList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach ($ipAddressList as $ip) {
            if (! empty($ip)) {
                // if you prefer, you can check for valid IP address here
                $ipAddress = $ip;
                break;
            }
        }
    } else if (! empty($_SERVER['HTTP_X_FORWARDED'])) {
        $ipAddress = $_SERVER['HTTP_X_FORWARDED'];
    } else if (! empty($_SERVER['HTTP_X_CLUSTER_CLIENT_IP'])) {
        $ipAddress = $_SERVER['HTTP_X_CLUSTER_CLIENT_IP'];
    } else if (! empty($_SERVER['HTTP_FORWARDED_FOR'])) {
        $ipAddress = $_SERVER['HTTP_FORWARDED_FOR'];
    } else if (! empty($_SERVER['HTTP_FORWARDED'])) {
        $ipAddress = $_SERVER['HTTP_FORWARDED'];
    } else if (! empty($_SERVER['REMOTE_ADDR'])) {
        $ipAddress = $_SERVER['REMOTE_ADDR'];
    }
    return $ipAddress;
}

echo "IP Address: " . getIpAddress();
?>
</center></h1>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <title>MyIP - DNSCheck.info</title>
        <link rel="icon" href="assets/favicon.ico">
        <link href="assets/css/bootstrap.min.css" rel="stylesheet">
        <link href="assets/css/style.css" rel="stylesheet">
        <link rel="apple-touch-icon" sizes="180x180" href="/img/apple-touch-icon.png">
        <link rel="icon" type="image/png" sizes="32x32" href="/img/favicon-32x32.png">
        <link rel="icon" type="image/png" sizes="16x16" href="/img/favicon-16x16.png">
        <link rel="manifest" href="/img/site.webmanifest">
        <link rel="mask-icon" href="/img/safari-pinned-tab.svg" color="#5bbad5">
        <link rel="shortcut icon" href="/img/favicon.ico">
        <meta name="msapplication-TileColor" content="#da532c">
        <meta name="msapplication-config" content="/img/browserconfig.xml">
        <meta name="theme-color" content="#ffffff">
    </head>
    <body>
        <footer class="footer">
<p class="text-center"><strong>Links</strong></p>
<p class="text-center"><a href="https://dnscheck.info/" target="_blank" rel="noreferrer noopener nofollow">DNS Check</a> | <a href="https://csr.caissl.com/" target="_blank" rel="noreferrer noopener nofollow">CSR Generator</a> | <a href="https://tool.caissl.com/" target="_blank" rel="noreferrer noopener nofollow">SSL Checker</a> | <a href="https://vpscanban.com/" target="_blank" rel="noreferrer noopener nofollow">Forum VPS</a> | <a href="https://dnscheck.info/whois.php" target="_blank" rel="noreferrer noopener nofollow">Domain Lookup</a> | <a href="https://dnscheck.info/myip.php" target="_blank" rel="noreferrer noopener nofollow">MyIP</a> </p>s
<p class="text-center"><strong>Feedback</strong></p>
<p class="text-center">Please send us your feedback concerning this service to lienhe@dotrungquan.info</p>
                <p class="text-center">Press <b>Ctrl+D (Windows)</b>, <b>Command+D (MacOS)</b> to bookmark DNSCheck.info</p>
                <p class="text-center">&copy; 2022 <a href="https://azdigi.com/"target="_blank">Host sponsored by AZDIGI</a></p>
        </footer>
            <center>  
                <figure class="image-center"><a href="https://blog.azdigi.com/thong-bao/azdigi-ra-mat-dich-vu-moi-uu-dai-soc-80-nhan-dip-sinh-nhat.html "target="_blank"><img src="/img/banner-treo-web-768x90-1.png" alt="AZDIGI Hosting Tốt Nhất Việt Nam" class="wp-image-2214" width="380" height="53"/></a>
                </figure>
            </center>
    </bodys
