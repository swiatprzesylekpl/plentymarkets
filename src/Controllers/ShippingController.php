<?php


namespace SwiatPrzesylek\Controllers;


use DateTime;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Shipping\Information\Contracts\ShippingInformationRepositoryContract;
use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
use Plenty\Modules\Order\Shipping\Package\Models\OrderShippingPackage;
use Plenty\Modules\Order\Shipping\PackageType\Contracts\ShippingPackageTypeRepositoryContract;
use Plenty\Modules\Order\Shipping\PackageType\Models\ShippingPackageType;
use Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Log\Loggable;
use SwiatPrzesylek\Constants;
use SwiatPrzesylek\Libs\SwiatPrzesylek\Client;
use SwiatPrzesylek\Libs\SwiatPrzesylek\Courier;

class ShippingController extends Controller
{
    use Loggable;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var OrderRepositoryContract $orderRepository
     */
    private $orderRepository;

    /**
     * @var AddressRepositoryContract $addressRepository
     */
    private $addressRepository;

    /**
     * @var OrderShippingPackageRepositoryContract $orderShippingPackage
     */
    private $orderShippingPackage;

    /**
     * @var ShippingInformationRepositoryContract
     */
    private $shippingInformationRepositoryContract;

    /**
     * @var StorageRepositoryContract $storageRepository
     */
    private $storageRepository;

    /**
     * @var ShippingPackageTypeRepositoryContract
     */
    private $shippingPackageTypeRepositoryContract;

    /**
     * @var  array
     */
    private $createOrderResult = [];

    /**
     * @var ConfigRepository
     */
    private $config;

    /**
     * @var Courier
     */
    private $courier;

    /**
     * ShipmentController constructor.
     *
     * @param Request $request
     * @param OrderRepositoryContract $orderRepository
     * @param AddressRepositoryContract $addressRepositoryContract
     * @param OrderShippingPackageRepositoryContract $orderShippingPackage
     * @param StorageRepositoryContract $storageRepository
     * @param ShippingInformationRepositoryContract $shippingInformationRepositoryContract
     * @param ShippingPackageTypeRepositoryContract $shippingPackageTypeRepositoryContract
     * @param ConfigRepository $config
     * @param \SwiatPrzesylek\Libs\SwiatPrzesylek\Courier $courier
     */
    public function __construct(
        Request $request,
        OrderRepositoryContract $orderRepository,
        AddressRepositoryContract $addressRepositoryContract,
        OrderShippingPackageRepositoryContract $orderShippingPackage,
        StorageRepositoryContract $storageRepository,
        ShippingInformationRepositoryContract $shippingInformationRepositoryContract,
        ShippingPackageTypeRepositoryContract $shippingPackageTypeRepositoryContract,
        ConfigRepository $config,
        Courier $courier
    )
    {
        $this->request = $request;
        $this->orderRepository = $orderRepository;
        $this->addressRepository = $addressRepositoryContract;
        $this->orderShippingPackage = $orderShippingPackage;
        $this->storageRepository = $storageRepository;

        $this->shippingInformationRepositoryContract = $shippingInformationRepositoryContract;
        $this->shippingPackageTypeRepositoryContract = $shippingPackageTypeRepositoryContract;

        $this->config = $config;

        $this->courier = $courier;
    }

    public function getLabels(Request $request, $orderIds)
    {
        $orderIds = $this->getOrderIds($request, $orderIds);
        $labels = [];
        foreach ($orderIds as $orderId) {
            $shippingPackages = $this->orderShippingPackage->listOrderShippingPackages($orderId);

            $this->getLogger(Constants::PLUGIN_NAME)
                ->error('getLabels::listOrderShippingPackages', $shippingPackages);

            /* @var \Plenty\Modules\Order\Shipping\Package\Models\OrderShippingPackage $shippingPackage */
            foreach ($shippingPackages as $shippingPackage) {
                $storageKey = explode('/', $shippingPackage->labelPath)[1];
                $storageObject = $this->storageRepository->getObject(Constants::PLUGIN_NAME, $storageKey, true);

                $this->getLogger(Constants::PLUGIN_NAME)
                    ->error('getLabels::$storageObject', $storageObject);

                $labels[] = $storageObject->body;
            }
        }
        $this->getLogger(Constants::PLUGIN_NAME)
            ->error('getLabels::$labels', $labels);

        return $labels;
    }

    /**
     * Registers shipment(s)
     *
     * @param Request $request
     * @param array $orderIds
     * @return array
     */
    public function registerShipments(Request $request, $orderIds)
    {
        $orderIds = $this->getOrderIds($request, $orderIds);
        $orderIds = $this->getOpenOrderIds($orderIds);
        $shipmentDate = date('Y-m-d');

        foreach ($orderIds as $orderId) {
            $shipmentItems = [];

            // gathering required data for registering the shipment
            $order = $this->orderRepository->findOrderById($orderId);
            // gets order shipping packages from current order
            $shippingPackages = $this->orderShippingPackage->listOrderShippingPackages($order->id);


            $spSender = $this->prepareSpSender();
            $spReceiver = $this->prepareSpReceiver($order);

            // iterating through packages
            /** @var \Plenty\Modules\Order\Shipping\Package\Models\OrderShippingPackage $responsePackage */
            foreach ($shippingPackages as $shippingPackage) {
                // determine packageType
                $packageType = $this->shippingPackageTypeRepositoryContract->findShippingPackageTypeById($shippingPackage->packageId);

                $requestPackage = $this->prepareRequestPackage($shippingPackage, $packageType);

                $this->getLogger(Constants::PLUGIN_NAME)
                    ->error('package data', [
                        'shipping_package' => $shippingPackage,
                        'package_type' => $packageType,
                        'delivery_address' => $order->deliveryAddress,
                        'sp_sender' => $spSender,
                        'sp_receiver' => $spReceiver,
                        'sp_package' => $requestPackage,
                    ]);

//                // shipping service providers API should be used here
                $response = $this->courier->createPreRouting($requestPackage, $spSender, $spReceiver);
//
                if ($response['result'] == Client::RESPONSE_FAIL) {
                    $this->getLogger(Constants::PLUGIN_NAME)
                        ->error('SP Response Error', $response);
                }
                $response = $response['response'];
                $responsePackage = $response['packages']['0'];

                $shipmentNumber = $responsePackage['external_id'];
                $storageKey = "{$shipmentNumber}.pdf";

                $storageObject = $this->saveLabelToS3(base64_decode($responsePackage['labels']['0']), $storageKey);
                $labelUrl = $this->storageRepository->getObjectUrl(Constants::PLUGIN_NAME, $storageKey, true, 60 * 24 * 7);

                $packageData = [
                    'packageNumber' => $shipmentNumber,
                    'labelPath' => $storageObject->key,
                ];

                $this->getLogger(Constants::PLUGIN_NAME)
                    ->error(
                        'storage data', [
                            '$storageKey' => $storageKey,
                            'labelUrl' => $labelUrl,
                            'storageObject' => $storageObject,
                            'packageData' => $packageData,
                        ]
                    );

                $this->orderShippingPackage->updateOrderShippingPackage($shippingPackage->id, $packageData);

                $shipmentItems[] = [
                    'labelUrl' => $labelUrl,
                    'shipmentNumber' => $shipmentNumber,
                ];
            }

            // adds result
            $this->createOrderResult[$orderId] = [
                'success' => true,
                'message' => 'Shipment successfully registered.',
                'newPackagenumber' => false,
                'packages' => $shipmentItems,
            ];

            $data = [
                'orderId' => $orderId,
                'transactionId' => implode(',', array_column($shipmentItems, 'shipmentNumber')),
                'shippingServiceProvider' => Constants::PLUGIN_NAME,
                'shippingStatus' => 'registered',
                'shippingCosts' => 0.00,
                'additionalData' => $shipmentItems,
                'registrationAt' => date(DateTime::W3C),
                'shipmentAt' => date(DateTime::W3C, strtotime($shipmentDate)),

            ];

            $this->getLogger(Constants::PLUGIN_NAME)
                ->error('shippingInformationData', $data);

            $shippingInformation = $this->shippingInformationRepositoryContract->saveShippingInformation($data);

            $this->getLogger(Constants::PLUGIN_NAME)
                ->error('$shippingInformation', $shippingInformation);
        }
        $this->getLogger(Constants::PLUGIN_NAME)
            ->error('createOrderResult', $this->createOrderResult);

        // return all results to service
        return $this->createOrderResult;
    }


    /**
     * Cancels registered shipment(s)
     *
     * @param Request $request
     * @param array $orderIds
     * @return array
     */
    public function deleteShipments(Request $request, $orderIds)
    {
        $orderIds = $this->getOrderIds($request, $orderIds);
        foreach ($orderIds as $orderId) {
            $shippingInformation = $this->shippingInformationRepositoryContract->getShippingInformationByOrderId($orderId);

            $this->getLogger(__METHOD__)
                ->error('delete shipment', $shippingInformation);

            if (isset($shippingInformation->additionalData) && is_array($shippingInformation->additionalData)) {
                foreach ($shippingInformation->additionalData as $additionalData) {

                    $shipmentNumber = $additionalData['shipmentNumber'];

                    // use the shipping service provider's API here
                    $response = [
                        'shipmentNumber' => $shipmentNumber,
                        'status' => 'shipment successfully deleted',
                    ];

                    $this->createOrderResult[$orderId] = $this->buildResultArray(
                        true,
                        $this->getStatusMessage($response),
                        false,
                        null);

                }

                // resets the shipping information of current order
                $this->shippingInformationRepositoryContract->resetShippingInformation($orderId);
            }


        }

        // return result array
        return $this->createOrderResult;
    }


    /**
     * Returns a formatted status message
     *
     * @param array $response
     * @return string
     */
    private function getStatusMessage($response)
    {
        return 'Code: ' . $response['result']; // should contain error code and descriptive part
    }

    /**
     * Returns all order ids with shipping status 'open'
     *
     * @param array $orderIds
     * @return array
     */
    private function getOpenOrderIds($orderIds)
    {

        $openOrderIds = [];
        foreach ($orderIds as $orderId) {
            $shippingInformation = $this->shippingInformationRepositoryContract->getShippingInformationByOrderId($orderId);
            if ($shippingInformation->shippingStatus == null || $shippingInformation->shippingStatus == 'open') {
                $openOrderIds[] = $orderId;
            }
        }

        return $openOrderIds;
    }


    /**
     * Returns an array in the structure demanded by plenty service
     *
     * @param bool $success
     * @param string $statusMessage
     * @param bool $newShippingPackage
     * @param array $shipmentItems
     * @return array
     */
    private function buildResultArray($success = false, $statusMessage = '', $newShippingPackage = false, $shipmentItems = [])
    {
        return [
            'success' => $success,
            'message' => $statusMessage,
            'newPackagenumber' => $newShippingPackage,
            'packages' => $shipmentItems,
        ];
    }

    /**
     * Returns all order ids from request object
     *
     * @param Request $request
     * @param $orderIds
     * @return array
     */
    private function getOrderIds(Request $request, $orderIds)
    {
        if (is_numeric($orderIds)) {
            $orderIds = [$orderIds];
        } else if (!is_array($orderIds)) {
            $orderIds = $request->get('orderIds');
        }

        return $orderIds;
    }

    /**
     * Retrieves the label file from a given URL and saves it in S3 storage
     *
     * @param string $body
     * @param string $key
     * @return \Plenty\Modules\Cloud\Storage\Models\StorageObject
     */
    private function saveLabelToS3($body, $key)
    {
        return $this->storageRepository->uploadObject(Constants::PLUGIN_NAME, $key, $body, true);

    }

    /**
     * @return array
     */
    private function prepareSpSender(): array
    {
        $spSender = [
            'name' => $this->config->get('SwiatPrzesylek.sender.name'),
            'company' => $this->config->get('SwiatPrzesylek.sender.company'),
            'address_line_1' => $this->config->get('SwiatPrzesylek.sender.address_line_1'),
            'address_line_2' => $this->config->get('SwiatPrzesylek.sender.address_line_2'),
            'zip_code' => $this->config->get('SwiatPrzesylek.sender.zip_code'),
            'city' => $this->config->get('SwiatPrzesylek.sender.city'),
            'country' => $this->config->get('SwiatPrzesylek.sender.country'),
            'tel' => $this->config->get('SwiatPrzesylek.sender.tel'),
        ];

        return $spSender;
    }

    /**
     * @param \Plenty\Modules\Order\Models\Order $order
     * @return array
     */
    private function prepareSpReceiver(Order $order): array
    {
        /** @var \Plenty\Modules\Account\Address\Models\Address $deliveryAddress */
        $deliveryAddress = $order->deliveryAddress;
        /** @var \Plenty\Modules\Order\Shipping\Countries\Models\Country $country */
        $country = $deliveryAddress->country;
        $spReceiver = [
            'name' => "{$deliveryAddress->firstName} {$deliveryAddress->lastName}",
            'company' => $deliveryAddress->companyName,
            'address_line_1' => "{$deliveryAddress->street} {$deliveryAddress->houseNumber}",
            'country' => $country->isoCode2,
            'zip_code' => $deliveryAddress->postalCode,
            'city' => $deliveryAddress->town,
            'tel' => $deliveryAddress->phone,
        ];

        return $spReceiver;
    }

    /**
     * @param \Plenty\Modules\Order\Shipping\Package\Models\OrderShippingPackage $package
     * @param \Plenty\Modules\Order\Shipping\PackageType\Models\ShippingPackageType $packageType
     * @return array
     */
    private function prepareRequestPackage(OrderShippingPackage $package, ShippingPackageType $packageType): array
    {
        $spPackage = [
            'weight' => $package->weight / 1000,
            'size_l' => $packageType->length,
            'size_w' => $packageType->width,
            'size_d' => $packageType->height,
            'value' => 10, // for test only
            'content' => 'Test',
        ];

        return $spPackage;
    }
}