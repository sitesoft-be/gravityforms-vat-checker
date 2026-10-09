<?php

/**
 * Fake VIES endpoint for integration tests (php -S 127.0.0.1:PORT router.php).
 * Behaviour is selected by the VAT number in the request. Every request is
 * counted per number in FAKE_VIES_STATE_DIR so tests can assert call counts.
 */

$body = file_get_contents('php://input') ?: '';
preg_match('~<(?:\w+:)?countryCode>([^<]*)<~', $body, $country);
preg_match('~<(?:\w+:)?vatNumber>([^<]*)<~', $body, $number);
$country = $country[1] ?? '';
$number  = $number[1] ?? '';

$state_dir = getenv('FAKE_VIES_STATE_DIR') ?: sys_get_temp_dir();
$counter   = $state_dir . '/calls-' . preg_replace('/\W/', '', $number);
$calls     = (int) @file_get_contents($counter) + 1;
file_put_contents($counter, (string) $calls);

$envelope = static function (string $inner): string {
    return '<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Header/><env:Body>'
        . $inner . '</env:Body></env:Envelope>';
};

$fault = static function (string $code) use ($envelope): void {
    http_response_code(500);
    header('Content-Type: text/xml; charset=UTF-8');
    echo $envelope('<env:Fault><faultcode>env:Server</faultcode><faultstring>' . $code . '</faultstring></env:Fault>');
};

$answer = static function (bool $valid) use ($envelope, $country, $number): void {
    header('Content-Type: text/xml; charset=UTF-8');
    $ns = 'urn:ec.europa.eu:taxud:vies:services:checkVat:types';
    echo $envelope(
        '<ns2:checkVatResponse xmlns:ns2="' . $ns . '">'
        . '<ns2:countryCode>' . $country . '</ns2:countryCode>'
        . '<ns2:vatNumber>' . $number . '</ns2:vatNumber>'
        . '<ns2:requestDate>2026-10-09+02:00</ns2:requestDate>'
        . '<ns2:valid>' . ($valid ? 'true' : 'false') . '</ns2:valid>'
        . '<ns2:name>' . ($valid ? 'NV SITESOFT TEST' : '---') . '</ns2:name>'
        . '<ns2:address>' . ($valid ? "Kerkstraat 1\n9000 Gent" : '---') . '</ns2:address>'
        . '</ns2:checkVatResponse>'
    );
};

switch ($number) {
    case '0000000097':
        $answer(true);
        break;
    case '0000000196':
        $answer(false);
        break;
    case '9000000001':
        $fault('MS_MAX_CONCURRENT_REQ');
        break;
    case '9000000002':
        $fault('MS_UNAVAILABLE');
        break;
    case '9000000003':
        sleep(3); // slower than the read timeout used in the test
        $answer(true);
        break;
    case '9000000004':
        http_response_code(503);
        header('Content-Type: text/html');
        echo '<html><body><h1>Service temporarily unavailable</h1></body></html>';
        break;
    case '9000000005':
        $fault('SOMETHING_UNEXPECTED');
        break;
    case '9000000006':
        // First call overloaded, retry succeeds.
        $calls === 1 ? $fault('MS_MAX_CONCURRENT_REQ') : $answer(true);
        break;
    case '9000000007':
        http_response_code(200);
        header('Content-Type: text/html');
        echo '<!DOCTYPE html><html><body>Maintenance</body></html>';
        break;
    default:
        $answer(false);
}
