<?php


namespace SwiatPrzesylek\Providers;


use Plenty\Modules\Order\Shipping\ServiceProvider\Services\ShippingServiceProviderService;
use Plenty\Plugin\ServiceProvider;
use SwiatPrzesylek\Constants;

class SwiatPrzesylekServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register()
    {
        // add REST routes by registering a RouteServiceProvider if necessary
//	     $this->getApplication()->register(ShippingTutorialRouteServiceProvider::class);
    }

    public function boot(ShippingServiceProviderService $shippingServiceProviderService)
    {

        $shippingServiceProviderService->registerShippingProvider(
            Constants::PLUGIN_NAME,
            '*** SwiatPrzesylek ***',
            [
                'SwiatPrzesylek\\Controllers\\ShippingController@registerShipments',
                'SwiatPrzesylek\\Controllers\\ShippingController@deleteShipments',
                'SwiatPrzesylek\\Controllers\\ShippingController@getLabels',
            ]);
    }
}