<?php


namespace SwiatPrzesylek\Libs\SwiatPrzesylek;


use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;
use SwiatPrzesylek\Constants;

class Courier
{
    use Loggable;

    /**
     * @var \SwiatPrzesylek\Libs\SwiatPrzesylek\Client
     */
    public $client;

    private $config;

    public function __construct(Client $client, ConfigRepository $configRepository)
    {
        $this->client = $client;
        $this->config = $configRepository;
        $this->client->username = $this->config->get('SwiatPrzesylek.access.apiUsername');
        $this->client->apiToken = $this->config->get('SwiatPrzesylek.access.apiToken');
    }

    public function createPreRouting(array $package, array $sender, array $receiver, array $options = [])
    {
        $env = $this->config->get('SwiatPrzesylek.env.type', Constants::ENV_DEV);

        if ($env == Constants::ENV_DEV) {
            return $this->dummyCreatePreRouting();
        }

        return $this->client->post('courier/create-pre-routing', [
            'package' => $package,
            'sender' => $sender,
            'receiver' => $receiver,
            'options' => $options,
            'options2' => [
                'label_type' => 'PDF_1_2',
            ],
        ]);
    }

    protected function dummyCreatePreRouting()
    {
        return [
            'result' => 'OK',
            'response' => [
                'number' => 1,
                'packages' => [
                    [
                        'package_id' => rand(100, 1000000) . uniqid('', true),
                        'result' => 'OK',
                        'log' => '',
                        'labels_no' => 1,
                        'labels' => [
                            base64_encode($this->client->download('https://www.dhl.com/content/dam/downloads/g0/express/customs_regulations_china/waybill_sample.pdf')),
                        ],
                        'labels_file_ext' => 'pdf',
                        'external_id' => rand(100, 1000000) . uniqid('', true),
                    ],
                ],
            ],
        ];
    }
}