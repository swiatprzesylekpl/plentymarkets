<?php


namespace SwiatPrzesylek\Libs\SwiatPrzesylek;


class Client
{
    const RESPONSE_FAIL = 'FAIL';
    const RESPONSE_OK = 'OK';

    public $username;
    public $apiToken;
    protected $baseUrl = 'https://api.swiatprzesylek.pl/V1';

    private $response;

    public function post($url, array $data)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "{$this->baseUrl}/{$url}");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->apiToken);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-type: application/json"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $response = curl_exec($ch);

        return $this->response = json_decode($response, true, 512, JSON_BIGINT_AS_STRING);
    }

    public function getFirstError()
    {
        if ($this->response['result'] == self::RESPONSE_FAIL) {
            $err = $this->response['error']['desc'] ?? 'SP API returned an error';
            $details = $this->response['error']['desc']['details'] ?? [];
            $details = current($details);

            return "$err | $details";
        }

        return '';
    }

    /**
     * Curl fetch content.
     *
     * @param string $fileUrl
     * @return bool|string
     */
    public function download(string $fileUrl)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fileUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $output = curl_exec($ch);
        curl_close($ch);

        return $output;
    }
}