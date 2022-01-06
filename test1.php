<?php

/**
 * Created by PhpStorm.
 * User: cedcoss
 * Date: 20/10/17
 * Time: 5:19 PM
 */

namespace App\Ecom\Controllers;

use App\Ecom\Components\Data;
use App\Ecom\Models\App;
use App\Ecom\Models\EcomPincodes;
use App\Ecom\Models\Merchant;
use App\Ecom\Models\MerchantLocations;
use App\Ecom\Models\MerchantManifest;
use App\Ecom\Models\MerchantOrders;
use App\Ecom\Models\MerchantPincodes;
use App\Ecom\Models\MerchantProducts;
use App\Ecom\Models\MerchantSettings;
use App\Ecom\Models\MerchantShipment;
use App\Ecom\Models\PickupAddresses;
use App\Ecom\Models\ProductVariants;
use Mpdf\Mpdf;
use Phalcon\Mvc\Controller;
use Shopify\Api;
use Shopify\Object\Transaction;
use Shopify\Service\InventoryLevelService;
use Shopify\Service\OrderFulfillmentService;
use Shopify\Service\OrderService;
use Shopify\Service\ProductService;
use Shopify\Service\RefundService;
use Shopify\Service\ShopService;

use Shopify\Service\TransactionService;


class ApiController extends Controller
{

	const SUCCESS_RESPONSE = [
		'success' => true,
		'message' => 'Shipment Successful'
	];

	const ERROR_CODES = [
		0 => 'Unauthorized',
		1 => 'HSN not found',
		2 => 'Category not found',
		3 => 'Invalid CSV format',
		4 => 'Missing Product Dimensions',
		5 => 'Ecom Dont Deliver Here',
		6 => 'This Order Is Already Fulfilled'
	];

	const REQUIRED_DATA_FIELDS = [
		'order_name',
		'line_items',
		'package_dimensions',
		'pickup_address',
		'order_id',
		'package_dimension_unit',
		'package_weight_unit',
		'shipping_carrier',
		'volumetric_weight'
	];

	const PRODUCT_UPDATION_FIELDS = [
		'id',
		'merchant_id',
		'product_id',
		'title',
		'location_id'
	];

	const REQUIRED_FIELDS = [
		'user_name',
		'api_password',
		'service_name',
		'service_description',
		'selected_countries',
		'shipping_method',
		'development_mode',
		'include_handling_fee',
		'show_frontend',
		'pickup_name',
		'pickup_type',
		'pickup_address_line1',
		'pickup_pincode',
		'pickup_phone',
		'return_name',
		'seller_gstin',
		'return_type',
		'return_address_line1',
		'return_pincode',
		'return_phone'
	];

    //editing here mritiyunjay
	public function getrefundedOrderAction()
	{

		$response = [
			'success' => false,
			'message' => 'No Data Found',
			'data' => []
		];

		if ($this->request->isget()) {
			$apiToken = $this->request->getHeader('Authorization');
			$merchant = Merchant::findFirst([
				"columns" => "id,token",
				"conditions" => "shop_url = ?1",
				"bind" => [1 => App::getShop($apiToken)]
			]);

			$mid = $merchant->id;
            //108

			$filters = ['merchant_id' => $mid, 'order_status' => 'refund'];

			$filtersEncoded = $this->request->getQuery('filters');
			$offset = $this->request->getQuery('page');
			if (isset($filtersEncoded) and !empty($filtersEncoded)) {
				$filtersArray = json_decode(base64_decode($filtersEncoded), true);
				if (is_array($filtersArray)) {
					$filters = array_merge($filters, $filtersArray);
				}
			}
			$orders = new MerchantOrders();

			if (count($orders->getRefundedOrders($filters, $offset)) != 0) {

				$ordersData = $orders->getOrders($filters, $offset);

				$response =
				[
					'success' => true,
					'message' => 'Success',
					'data' => $ordersData['orders'],
					'count' => $ordersData['total_counts']
				];
			}
			return $this->response->setJsonContent($response);
		}
		return $this->response->setJsonContent($response);
	}
	public function calculateRateAction(){
		if ($this->request->isPost()) {
			$data = $this->request->getJsonRawBody();
			$customerCode=$data->customerCode;
			$productType=$data->productType;
			$originPincode=$data->originPincode;
			$destinationPincode=$data->destinationPincode;
			$chargeableWeight=$data->chargeableWeight;
			$codAmount=$data->codAmount;
			$sendDataToEcom=[
				'customerCode'=> (string)$customerCode,
				'orginPincode'=>$originPincode,
				'destinationPincode'=>$destinationPincode,
				'productType'=>(string) $productType,
				'chargeableWeight'=>$chargeableWeight,
				'codAmount'=>$codAmount
			];
			$sendDataToEcom=json_encode($sendDataToEcom);
			print_r($sendDataToEcom);
		}
	}
	public function cancelOrderAction()
	{
		$response = array(
			'success' => false,
			'message' => 'Order Cancellation Failed'
		);
		try {
			$idEncoded = $this->request->getJsonRawBody(true);
			if (isset($idEncoded['id']) && !empty($idEncoded['id'])) {
				$id = $idEncoded['id'];
				$id_to_cancel = MerchantOrders::findFirst([
					'columns' => 'order_id',
					'conditions' => 'id =?1',
					'bind' => [1 => $id]
				]);
				$shopifyOrderId = $id_to_cancel->order_id;

				if (isset($shopifyOrderId) && !empty($shopifyOrderId)) {
					$apiToken = $this->request->getHeader('Authorization');
					$token = $this->tokens();
					$domain = App::getShop($apiToken);
					$decision = MerchantSettings::carrierShow(App::getShop($apiToken));
					$filterArray = [];
					for ($i = 0; $i < count($decision); $i++) {
						$filterArray[$decision[$i]['config_path']] = $decision[$i]['value'];
					}

					$username = Data::getApiUserName(Merchant::getMerchant(App::getShop($apiToken))->id);
					$password = Data::getApiPassword(Merchant::getMerchant(App::getShop($apiToken))->id);

					$obj = new \EcomExpressAPI\API($username->value, $password->value);
					if ($filterArray['development_mode'] == 1) {
						$obj->developmentMode($username->value, $password->value);
					}

					$cancelOrderData = MerchantShipment::find([
						'columns' => 'tracking_number,fulfillment_id',
						'conditions' => 'order_id = ?1',
						'bind' => [1 => $shopifyOrderId]
					]);

					$cancelOrderData = $cancelOrderData->toArray();
					$successMessage = array();

					if (isset($cancelOrderData) && !empty($cancelOrderData)) {
						foreach ($cancelOrderData as $cancelData) {
							if (!isset($cancelData['tracking_number']) || (isset($cancelData['tracking_number']) && empty($cancelData['tracking_number']))) {
								continue;
							}
							$cancelAtEcom = $obj->cancel($cancelData['tracking_number']);
							if (isset($cancelAtEcom[0]['success']) && $cancelAtEcom[0]['success'] == true) {
								$successMessage[] = "{$cancelData['tracking_number']} cancelled at Ecom";
								$cancelAtShopify = MerchantShipment::cancelFullfilemnt($token, $domain, $shopifyOrderId, $cancelData['fulfillment_id']);
								$cancelAtShopify['fulfillment']['status'] = 'cancelled';
								if (isset($cancelAtShopify['fulfillment']['status']) && $cancelAtShopify['fulfillment']['status'] == 'cancelled') {
									$orderStatusInOrderTable = MerchantOrders::findFirst([
										'conditions' => 'order_id =?1',
										'bind' => [1 => $shopifyOrderId]
									]);

									if (isset($orderStatusInOrderTable) && !empty($orderStatusInOrderTable)) {
										$orderStatusInOrderTable->fulfillment_status = 'unfulfilled';
										$orderStatusInOrderTable->update();
									}

									$orderStatusInShipmentTable = MerchantShipment::findFirst([
										'conditions' => 'fulfillment_id =?1',
										'bind' => [1 => $cancelData['fulfillment_id']]
									]);

									$orderStatusInShipmentTable->shipment_status = 'cancelled';
									$orderStatusInShipmentTable->ecom_status = 'Shipment RTO Lock';
									$orderStatusInShipmentTable->update();

									$successMessage[] = "{$cancelData['fulfillment_id']} -> was cancelled at Shopify";
								} else {
									$successMessage[] = "{$cancelData['fulfillment_id']} -> failed to cancelled at shopify";
								}
							} else {
								$successMessage[] = "{$cancelData['tracking_number']} -> this tracking number was unable to cancelled at Ecom";
							}
							$response['success'] = true;
							$response['message'] = implode(' | ', $successMessage);
						}
					}
				}
			}
		} catch (\Exception $exception) {
			print_r($exception->getMessage());
			die;
			$response['success'] = false;
			$response['message'] = implode(' | ', $successMessage);

			$this->getDI()->get('log')
			->logContent($exception->getMessage(), 7, "exception-{$domain}.log");
		}
		return $this->response->setJsonContent($response);
	}

	public function shipmentAction()
	{
		try {
			if ($this->request->isPost()) {
				sleep(1);
				$labelParams = [];
				$token = $this->tokens();
				$apiToken = $this->request->getHeader('Authorization');
				$data = $this->request->getJsonRawBody();
				$validData = $this->request->getJsonRawBody(true);
				foreach (self::REQUIRED_DATA_FIELDS as $REQUIRED_DATA_FIELD) {
					if (!isset($validData[$REQUIRED_DATA_FIELD]) or empty($validData[$REQUIRED_DATA_FIELD])) {
						return $this->response->setJsonContent(
							[
								'success' => false,
								'message' => 'Missing Required Shipment Data',
								'code' => 'Shipment Failed',
								'display' => true
							]
						);
					}
				}
				$pieces = 0;
				$weights = 0;
				$title = [];
				$labelTitle = [];
				$category = [];
				$totalTax = 0;
				$igstrate = 0;
				$taxtitle = '';
				$pickupAddress = '';
				$parcel = [];
				$lineItems = ($data->line_items);
				$packageDimentions = $data->package_dimensions;
				if (!isset($data->pickup_address)) {
					return $this->response->setJsonContent(
						[
							'success' => false,
							'message' => 'No Address Found'
						]
					);
				} else {
					$pickupAddress = $data->pickup_address;
				}
				$lineItemsId = '';
				$quantity = '';
				$response = ['success' => false, 'message' => 'Fulfillmet failed'];
				$stat = false;
				$filters = ['merchant_id' => Merchant::getMerchant(App::getShop($apiToken))->id];
				$domain = App::getShop($apiToken);
				if (isset($data) and !empty($data) and isset($data->order_id) and !empty($data->order_id)) {
					$decision = MerchantSettings::carrierShow(App::getShop($apiToken));
					$filterArray = [];
					for ($i = 0; $i < count($decision); $i++) {
						$filterArray[$decision[$i]['config_path']] = $decision[$i]['value'];
					}

					foreach (self::REQUIRED_FIELDS as $REQUIRED_FIELD) {
						if (
							$REQUIRED_FIELD == 'development_mode' or $REQUIRED_FIELD == 'include_handling_fee'
							or $REQUIRED_FIELD == 'show_frontend' or $REQUIRED_FIELD == 'shipping_method'
						) {
							if (isset($filterArray[$REQUIRED_FIELD]) and ((string)$filterArray[$REQUIRED_FIELD] == '0' or (string)$filterArray[$REQUIRED_FIELD] == '1')) {
								continue;
							} else {
								return $this->response->setJsonContent(
									[
										'success' => false,
										'message' => 'Unsaved Required Settings'
									]
								);
							}
						} elseif (empty($filterArray[$REQUIRED_FIELD])) {
							return $this->response->setJsonContent(
								[
									'success' => false,
									'message' => 'Unsaved Required Settings'
								]
							);
						}
					}

					$shopdetails = Merchant::findFirst([
						'columns' => 'shop_details',
						'conditions' => 'id =?1',
						'bind' => [1 => App::getMerchant($apiToken)->id]
					])->toArray();

					$shopdetails = json_decode($shopdetails['shop_details']);
					$shopdetails = json_decode($shopdetails, true);

					$parcelDetails = self::getOrders($token, $apiToken, (int)$data->order_id);
					$parcelDetails = json_encode($parcelDetails, true);
					$details = json_decode($parcelDetails, true);
					$det = json_decode($details, true);
					if ($det['fulfillment_status'] == 'fulfilled') {
						$updateShipmentStatus = MerchantOrders::findFirst(
							[
								'conditions' => 'order_id =?1 AND order_name =?2',
								'bind' => [1 => $data->order_id, 2 => $data->order_name]
							]
						);
						if (isset($updateShipmentStatus) and !empty($updateShipmentStatus)) {
							$updateShipmentStatus->fulfillment_status = 'fulfilled';
							$updateShipmentStatus->update();
						}
						return $this->response->setJsonContent(
							[
								'success' => false,
								'message' => 'Order ' . $det['name'] . ' is already fulfilled',
								'code' => self::ERROR_CODES[6],
								'display' => true
							]
						);
					}
					$checkAvailability = $this->checkAvailability(json_decode($det['shipping_address'], true)['zip']);
					if ($checkAvailability == 'Not Servicable') {
						$updateShipmentStatus = MerchantOrders::findFirst(
							[
								'conditions' => 'order_id =?1 AND order_name =?2',
								'bind' => [1 => $data->order_id, 2 => $data->order_name]
							]
						);
						if (isset($updateShipmentStatus) and !empty($updateShipmentStatus)) {
							$updateShipmentStatus->shipment_status = 'Not Servicable';
							$updateShipmentStatus->update();
						}
						return $this->response->setJsonContent(
							[
								'success' => false,
								'message' => 'Delivery pincode not servicable by ecom for order name ' . $det['name'],
								'code' => self::ERROR_CODES[5],
								'display' => true
							]
						);
					}

					$date = $det['created_at']['date'];

					$username = Data::getApiUserName(Merchant::getMerchant(App::getShop($apiToken))->id);
					$password = Data::getApiPassword(Merchant::getMerchant(App::getShop($apiToken))->id);
					foreach ($data->line_items as $key => $value) {
						if ($value->fulfilled_quantity == 0) {
							continue;
						}
						$pieces = $pieces + $value->fulfilled_quantity;
						$weights = $weights + $value->weight;
						$title[] = $value->title;
						$labelTitle[] = $value->name . ' ( x ' . $value->fulfilled_quantity . ' )';

						if (isset($labelTitle) and empty($labelTitle)) {
							$labelTitle[] = $value->title . ' ( x ' . $value->fulfilled_quantity . ' )';
						}
						$category[] = $value->category;
					}

					$l = '';
					$b = '';
					$h = '';
					foreach ($packageDimentions as $ke => $val) {
						$l = $packageDimentions->length;
						$b = $packageDimentions->breadth;
						$h = $packageDimentions->height;
					}
					if (!is_numeric($l) or !is_numeric($b) or !is_numeric($h)) {
						return $this->response->setJsonContent(
							[
								'success' => false,
								'message' => 'Inappropriate package dimensions'
							]
						);
					}

					if ($l <= 0 or $b <= 0 or $h <= 0) {

						return $this->response->setJsonContent(
							[
								'success' => false,
								'message' => 'Inappropriate package dimensions'
							]
						);
					}
					$volumetricWeight = 0.2;
					if (isset($data->volumetric_weight) and is_numeric($data->volumetric_weight)) {
						$volumetricWeight = $data->volumetric_weight;
					}
					if ($data->package_dimension_unit == 'm') {
						$l = $l * 100;
						$b = $b * 100;
						$h = $h * 100;
					}
					$itemDescription = '';
					if (is_array($title)) {
						$itemDescription = implode(' + ', $title);
					}
					$actualWeight = '';
					if ($data->package_weight_unit == 'gm') {
						$actualWeight = $data->package_weight / 1000;
					} elseif ($data->package_weight_unit == 'lb') {
						$actualWeight = $data->package_weight / 2.2046226219;
					} elseif ($data->package_weight_unit == 'oz') {
						$actualWeight = $data->package_weight / 35.274;
					} else {
						$actualWeight = $data->package_weight;
					}

					$actualWeight = $actualWeight <= 0.1 ? 0.2 : $actualWeight;

					$obj = new \EcomExpressAPI\API($username->value, $password->value);
					if ($filterArray['development_mode'] == 1) {
						$obj->developmentMode($username->value, $password->value);
					}
					$custPhone = MerchantOrders::findFirst([
						'columns' => 'customer_phone',
						'conditions' => 'order_id =?1',
						'bind' => [1 => $data->order_id]
					]);

					if ($custPhone->customer_phone == null) {
						$custPhone->customer_phone = '0000000000';
					}

					if ($filterArray['pickup_address_line2'] == null) {
						$filterArray['pickup_address_line2'] = '';
					}

					if ($filterArray['return_address_line2'] == null) {
						$filterArray['return_address_line2'] = '';
					}


					$product = 'PPD';
					$collectableAmount = 0;
					$declaredAmount = $det['total_price'];

					if (isset($det['financial_status']) and $det['financial_status'] == 'pending') {
						$product = 'COD';
						$collectableAmount = $det['total_price'];
						$declaredAmount = $det['total_price'];
					}

					if (isset($det['tax_lines']) and !empty($det['tax_lines'])) {
						$totalTax = $det['total_tax'];
						$taxes = json_decode($det['tax_lines'][0], true);
						$taxtitle = $taxes['title'];
						$igstrate = $taxes['rate'];
					} else {
						$totalTax = $det['total_tax'];
						$taxtitle = 'Igst';
					}

                    /// Ecom ==> Pincodes ///

					$parcel_codes = EcomPincodes::findFirst([
						'columns' => 'state_code,dc_code',
						'conditions' => 'pincode =?1',
						'bind' => [1 => json_decode($det['shipping_address'], true)['zip']]
					]);

					$parcel_return_codes = EcomPincodes::findFirst([
						'columns' => 'city,state',
						'conditions' => 'pincode =?1',
						'bind' => [1 => $filterArray['return_pincode']]
					]);
                    //print_r($data->line_items);die;
					foreach ($data->line_items as $lineItemKey => $lineItemValue) {
						if (
							!isset($data->line_items[$lineItemKey]->hsn_code) or
							empty($data->line_items[$lineItemKey]->hsn_code)
						) {
							return $this->response->setJsonContent(
								[
									'success' => false,
									'message' => 'Missing HSN code or Category',
									'code' => self::ERROR_CODES[1]
								]
							);
						} elseif (
							!isset($data->line_items[$lineItemKey]->category) or
							empty($data->line_items[$lineItemKey]->category)
						) {
							return $this->response->setJsonContent(
								[
									'success' => false,
									'message' => 'Missing HSN Code or Cateogry',
									'code' => self::ERROR_CODES[1]
								]
							);
						} elseif (!isset($data->line_items[$lineItemKey]->location_id, $data->line_items[$lineItemKey]->length, $data->line_items[$lineItemKey]->breadth, $data->line_items[$lineItemKey]->height) or empty($data->line_items[$lineItemKey]->length) or empty($data->line_items[$lineItemKey]->location_id) or empty($data->line_items[$lineItemKey]->breadth) or empty($data->line_items[$lineItemKey]->height)) {
							return $this->response->setJsonContent(
								[
									'success' => false,
									'message' => 'Missing Product Dimensions or Location Id',
									'code' => self::ERROR_CODES[4]
								]
							);
						}
                        // Location Name
						$locationName = MerchantLocations::findFirst([
							'columns' => 'location_name',
							'conditions' => 'location_id =?1',
							'bind' => [1 => $data->line_items[$lineItemKey]->location_id]
						]);

						if ($prodExist = MerchantProducts::findFirst(
							"product_id = {$data->line_items[$lineItemKey]->product_id} AND 
							merchant_id = {$filters['merchant_id']} AND 
							variant_id = {$data->line_items[$lineItemKey]->variant_id}"
						)
					) {
							$prodExist->hsn_code = $data->line_items[$lineItemKey]->hsn_code;
							$prodExist->category = $data->line_items[$lineItemKey]->category;
							$prodExist->length = $data->line_items[$lineItemKey]->length;
							$prodExist->breadth = $data->line_items[$lineItemKey]->breadth;
							$prodExist->height = $data->line_items[$lineItemKey]->height;
							$prodExist->location_id = $data->line_items[$lineItemKey]->location_id;
							$prodExist->location_name = $locationName->location_name;
							$prodExist->update();
						} else {
							$prodExist = new MerchantProducts();
							$prodExist->save([
								'merchant_id' => $filters['merchant_id'],
								'product_id' => $data->line_items[$lineItemKey]->product_id,
								'variant_id' => $data->line_items[$lineItemKey]->variant_id,
								'category' => $data->line_items[$lineItemKey]->category,
								'title' => $data->line_items[$lineItemKey]->title,
								'hsn_code' => $data->line_items[$lineItemKey]->hsn_code,
								'length' => $data->line_items[$lineItemKey]->length,
								'breadth' => $data->line_items[$lineItemKey]->breadth,
								'height' => $data->line_items[$lineItemKey]->height,
								'location_id' => $data->line_items[$lineItemKey]->location_id,
								'location_name' => $locationName->location_name
							]);
						}
					}
					$flag = false;
					foreach ($data->line_items as $line_item) {
						if ($line_item->fulfilled_quantity != $line_item->total_quantity) {
							$flag = true;
						}
					}
					if ($flag == true) {
						$collectableAmount = 0;
						$totalTax = 0;
						foreach ($data->line_items as $lineItemValue) {
							$collectableAmount += ($lineItemValue->price * $lineItemValue->fulfilled_quantity) + ($lineItemValue->price * $lineItemValue->fulfilled_quantity) * json_decode($lineItemValue->tax_lines[0], true)['rate'];
							$totalTax += ($lineItemValue->price * $lineItemValue->fulfilled_quantity) * json_decode($lineItemValue->tax_lines[0], true)['rate'];
						}
					}

					if ($product == 'PPD') {
						$collectableAmount = 0;
						$totalTax = 0;
					}

					$parcel = [
						'AWB_NUMBER' => '',
						'ORDER_NUMBER' => (string)$data->order_id,
						'PRODUCT' => (string)$product,
						'CONSIGNEE' => (string)json_decode($det['shipping_address'], true)['name'],
						'CONSIGNEE_ADDRESS1' => (string)json_decode($det['shipping_address'], true)['address1'],
						'CONSIGNEE_ADDRESS2' => (string)json_decode($det['shipping_address'], true)['address2'],
						'CONSIGNEE_ADDRESS3' => '',
						'DESTINATION_CITY' => (string)json_decode($det['shipping_address'], true)['city'],
						'PINCODE' => (string)json_decode($det['shipping_address'], true)['zip'],
						'STATE' => (string)json_decode($det['shipping_address'], true)['province_code'],
						'MOBILE' => (string)preg_replace('/\D/', '', $custPhone->customer_phone),
						'TELEPHONE' => (string)preg_replace('/\D/', '', $custPhone->customer_phone),
						'ITEM_DESCRIPTION' => (string)$itemDescription,
						'PIECES' => (int)$pieces,
						'COLLECTABLE_VALUE' => (float)$collectableAmount,
						'DECLARED_VALUE' => (float)$declaredAmount,
						'ACTUAL_WEIGHT' => (string)$actualWeight,
                        'VOLUMETRIC_WEIGHT' => (string)$volumetricWeight, // Optional
                        'LENGTH' => (int)$l, // Mandatory
                        'BREADTH' => (int)$b, // Mandatory
                        'HEIGHT' => (int)$h, // Mandatory
                        'PICKUP_NAME' => (string)$pickupAddress->pickup_name,
                        'PICKUP_ADDRESS_LINE1' => (string)$pickupAddress->pickup_address_line1,
                        'PICKUP_ADDRESS_LINE2' => (string)$pickupAddress->pickup_address_line2,
                        'PICKUP_PINCODE' => $pickupAddress->pickup_pincode,
                        'PICKUP_PHONE' => (string)$pickupAddress->pickup_phone,
                        'PICKUP_MOBILE' => (string)$pickupAddress->pickup_phone,
                        'RETURN_NAME' => (string)$filterArray['return_name'],
                        'RETURN_ADDRESS_LINE1' => (string)$filterArray['return_address_line1'],
                        'RETURN_ADDRESS_LINE2' => (string)$filterArray['return_address_line2'],
                        'RETURN_PINCODE' => (string)$filterArray['return_pincode'],
                        'RETURN_PHONE' => (string)$filterArray['return_phone'],
                        'RETURN_MOBILE' => (string)$filterArray['return_phone'],
                        'ADDONSERVICE' => '["NDD"]', // Optional
                        'DG_SHIPMENT' => 'false', // Mandatory
                        'ADDITIONAL_INFORMATION' => [
                        	'MULTI_SELLER_INFORMATION' => []
                        ]
                    ];
                    $sgst = 0;
                    $cgst = 0;
                    $igst = 0;
                    $sgstRate = 0;
                    $cgstRate = 0;
                    if (
                    	$shopdetails['province_code'] ==
                    	json_decode($det['shipping_address'], true)['province_code']
                    ) {
                    	$sgst = ($totalTax) / 2;
                    	$cgst = ($totalTax) / 2;
                    	$sgstRate = 0.9;
                    	$cgstRate = 0.9;
                    	$igstrate = 0;
                    	$taxtitle = json_decode($det['shipping_address'], true)['province_code'] . ' GST';
                    } else {
                    	$igst = $totalTax;
                    }


                    foreach ($data->line_items as $line_item_key => $line_item_value) {
                    	if ($line_item_value->fulfilled_quantity == 0) {
                    		continue;
                    	}
                    	if (isset($line_item_value->category) and empty(trim($line_item_value->category))) {
                    		return $this->response->setJsonContent(
                    			[
                    				'success' => false,
                    				'message' => 'Missing HSN Code or Cateogry',
                    				'code' => self::ERROR_CODES[2]
                    			]
                    		);
                    	}

                    	if ($hsnExist = MerchantProducts::findFirst(
                    		[
                    			'columns' => 'hsn_code',
                    			'conditions' => 'product_id =?1 AND merchant_id =?2',
                    			'bind' => [1 => $data->line_items[$line_item_key]->product_id, 2 => $filters['merchant_id']]
                    		]
                    	)) {
                    		$parcel['ADDITIONAL_INFORMATION']['MULTI_SELLER_INFORMATION'][] = [
                                //'SELLER_TIN' => 'SELLER_TIN_1234', // Optional
                    			'INVOICE_NUMBER' => (string)$data->order_id,
                    			'INVOICE_DATE' => date('D-M-Y', strtotime($date)),
                                //'ESUGAM_NUMBER' => 'eSUGAM_1234', // Optional
                                'ITEM_CATEGORY' => (string)$line_item_value->category, // Mandatory
                                'ITEM_DESCRIPTION' => (string)$line_item_value->title,
                                'SELLER_NAME' => (string)$shopdetails['name'],
                                'SELLER_ADDRESS' => (string)$shopdetails['address1'] . ',' . $shopdetails['address2'],
                                'SELLER_STATE' => (string)$shopdetails['province_code'],
                                'SELLER_PINCODE' => (string)$shopdetails['zip'],
                                'SELLER_TIN' => (string)$filterArray['seller_tin'],
                                //'PACKING_TYPE' => 'Box', // Optional
                                'PICKUP_TYPE' => (string)$pickupAddress->pickup_type,
                                'RETURN_TYPE' => (string)$filterArray['return_type'],
                                'PICKUP_LOCATION_CODE' => '', // Optional
                                'SELLER_GSTIN' => (string)$filterArray['seller_gstin'], // Mandatory
                                'GST_HSN' => (string)$hsnExist->hsn_code, // Mandatory
                                'GST_ERN' => 'ERN', // Mandatory
                                'GST_TAX_NAME' => (string)$taxtitle, // Mandatory
                                'GST_TAX_BASE' => (float)$collectableAmount - $totalTax, // Mandatory
                                'DISCOUNT' => (float)$det['total_discounts'],
                                'GST_TAX_RATE_CGSTN' => (float)$cgstrate = $cgst > 0 ? $sgstRate : '0', // Mandatory
                                'GST_TAX_RATE_SGSTN' => (float)$cgstrate = $cgst > 0 ? $cgstRate : '0', // Mandatory
                                'GST_TAX_RATE_IGSTN' => (float)$igstrate, // Mandatory
                                'GST_TAX_TOTAL' => (float)$totalTax,
                                'GST_TAX_CGSTN' => (float)$sgst, // Mandatory
                                'GST_TAX_SGSTN' => (float)$cgst, // Mandatory
                                'GST_TAX_IGSTN' => (float)$igst // Mandatory
                            ];
                        }
                    }
                    $AWB = $obj->addParcel($parcel);
                    $num = $AWB->send($product);
                    $success = '';
                    $awbNum = '';
                    for ($i = 0; $i < count($num); $i++) {
                    	$success = ($num[$i]['success']);
                    	$awbNum = $num[$i]['awb'];
                    }
                    if ($success == 1) {
                    	$newLineItems = array();
                    	$fulfillmentIds = array();
                    	if (isset($lineItems) and !empty($lineItems)) {
                    		foreach ($lineItems as $item) {
                    			$quantity = $item->fulfilled_quantity;
                    			if (!isset($quantity) or (int)$quantity == 0) {
                    				continue;
                    			}
                    			$locationIdKey = $item->location_id;
                    			$newLineItems[$locationIdKey][] = $item;
                    		}
                    		foreach ($newLineItems as $newLineItemKey => $newLineItemValue) {
                    			$ship = new MerchantShipment();
                    			$status = $ship
                    			->ship(
                    				$token,
                    				$domain,
                    				(int)$data->order_id,
                    				$awbNum,
                    				$newLineItemValue,
                    				'https://ecomexpress.in/tracking/?awb_field=' . $awbNum . '&s=',
                    				$newLineItemKey
                    			);
                    			if (isset($status['success']) && $status['success'] == true) {
                    				$fulfillmentIds = $status['ids'];
                    			}
                    			if ($status['success'] == false) {
                    				try {
                    					foreach ($fulfillmentIds as $fulfillmentId) {
                    						MerchantShipment::cancelFullfilemnt(
                    							$token,
                    							$domain,
                    							(int)$data->order_id,
                    							$fulfillmentId
                    						);
                    					}
                    					$obj->cancel($awbNum);
                    				} catch (\Exception $exception) {
                    					$this->getDI()
                    					->get('log')
                    					->logContent(
                    						var_export($exception->getMessage(), true),
                    						7,
                    						'cancelshipment.log'
                    					);
                    				}
                    				return $this->response->setJsonContent(
                    					[
                    						'success' => false,
                    						'message' => 'Failed to fulfill At Shopify'
                    					]
                    				);
                    			}
                    		}
                    	} else {
                    		return $this->response->setJsonContent(
                    			[
                    				'success' => false,
                    				'message' => 'No items to fulfill'
                    			]
                    		);
                    	}
                    	$token = $this->tokens();
                    	$client = new Api([
                    		'api_key' => \App\Ecom\Models\App::APP_API_KEY,
                    		'api_secret' => \App\Ecom\Models\App::APP_SECRET_KEY,
                    		'myshopify_domain' => App::getShop($apiToken),
                    		'access_token' => $token
                    	]);
                    	$helper = new OrderFulfillmentService($client);
                    	$oId = (int)$data->order_id;
                    	sleep(4);
                    	$id = $helper->all($oId)[0]->id;
                    	$ordersId = (int)$data->order_id;

                    	if (isset($awbNum) and isset($ordersId) and isset($id)) {

                    		$trackingNums = [];
                    		$trackingNumber = MerchantOrders::findFirst("order_id = '{$ordersId}' ");
                    		if (isset($trackingNumber->tracking_number)) {
                    			$trackingNums = explode(',', $trackingNumber->tracking_number);
                    		}
                    		sleep(4);
                    		$parcelDetails1 = self::getOrders($token, $apiToken, (int)$data->order_id);

                    		$parcelDetails1 = json_encode($parcelDetails1, true);
                    		$details1 = json_decode($parcelDetails1, true);
                    		$det1 = json_decode($details1, true);


                    		$lineItems1 = $det1['line_items'];

                    		$shipData = json_encode(array_values($lineItems1), true);

                    		$trackingNums[] = $awbNum;
                    		$trackingNumber->tracking_number = implode(',', $trackingNums);
                    		$trackingNumber->ecom_status = 'Ready To Be Shipped';
                    		$trackingNumber->update();

                    		$labelParams['name'] = json_decode($det['shipping_address'], true)['name'];
                    		$labelParams['shipper_name'] = $shopdetails['name'];
                    		$labelParams['awb_number'] = $awbNum;
                    		$labelParams['order_number'] = $data->order_name;
                    		$labelParams['payment'] = $product;
                    		$labelParams['state_code'] = $parcel_codes['state_code'];
                    		$labelParams['dc_code'] = $parcel_codes['dc_code'];
                    		$labelParams['phone'] = $custPhone->customer_phone;
                    		$labelParams['customer_add1'] = json_decode($det['shipping_address'], true)['address1'];
                    		$labelParams['customer_add2'] = json_decode($det['shipping_address'], true)['address2'];
                    		$labelParams['pincode'] = json_decode($det['shipping_address'], true)['zip'];
                    		$labelParams['item_description'] = implode(',<br>', $labelTitle);
                    		$labelParams['quantity'] = $pieces;
                    		$labelParams['length'] = $l;
                    		$labelParams['breadth'] = $b;
                    		$labelParams['height'] = $h;
                    		$labelParams['value'] = $collectableAmount;
                    		$labelParams['weight'] = $actualWeight;
                    		$labelParams['date'] = date("d-M-Y H:i", strtotime($date));
                    		$labelParams['return_address'] =
                    		$filterArray['return_address_line1']
                    		. ' ' . $filterArray['return_address_line2']
                    		. ' ' . $parcel_return_codes['city']
                    		. ' ' . $parcel_return_codes['state']
                    		. ' ' . $filterArray['return_pincode'];

                    		$this->generateLabel($labelParams);

                    		$save = new MerchantShipment();
                    		$save->save([
                    			'merchant_id' => $filters['merchant_id'],
                    			'order_id' => $ordersId,
                    			'order_name' => $data->order_name,
                    			'tracking_number' => $awbNum,
                    			'tracking_url' => 'https://ecomexpress.in/tracking/?awb_field=' . $awbNum . '&s=',
                    			'shipment_status' => 'Fulfilled',
                    			'shipment_data' => $shipData,
                    			'fulfillment_id' => $id,
                    			'created_at' => date("Y-m-d"),
                    			'package_dimensions' => json_encode($packageDimentions, true),
                    			'shipping_carrier' => $data->shipping_carrier,
                    			'ecom_status' => 'Shipment In process',
                    			'order_fulfilled_at' => date("Y-m-d"),
                    			'label_data' => serialize($labelParams)
                    		]);

                    		$stat = true;
                    	}
                    } elseif ($success == 0) {
                    	$responseReason = '';
                    	for ($i = 0; $i < count($num); $i++) {
                    		$responseReason = ($num[$i]['reason']);
                    	}
                    	return $this->response->setJsonContent(
                    		[
                    			'success' => false,
                    			'message' => $responseReason,
                    			'display' => true
                    		]
                    	);
                    }
                }
                if ($stat == true) {
                	sleep(2);
                	return $this->response->setJsonContent(self::SUCCESS_RESPONSE);
                } else {
                	return $this->response->setJsonContent(
                		[
                			'success' => false,
                			'message' => 'Failed To Ship At Ecom'
                		]
                	);
                }
            }
        } catch (\Exception $exception) {
        	$context = [
        		'path' => __METHOD__,
        		'parcel_data' => $parcel,
        		'data' => $data
        	];
        	$this->getDI()->get('log')
        	->logContent($exception->getMessage(), 7, "exception-{$domain}.log", $context);
        }
        return $this->response->setJsonContent($response);
    }

    private function checkAvailability($pincode)
    {
    	$response = 'Not Servicable';
    	$servicablePincode = EcomPincodes::findFirst(
    		[
    			'columns' => 'id',
    			'conditions' => 'pincode =?1',
    			'bind' => [1 => $pincode]
    		]
    	);
    	if (isset($servicablePincode) and !empty($servicablePincode)) {
    		$response = 'Servicable';
    	}
    	return $response;
    }

    private function generateLabel($params = [
    	'name',
    	'awb_number',
    	'order_number',
    	'payment',
    	'state_code',
    	'dc_code',
    	'phone',
    	'customer_add1',
    	'customer_add2',
    	'pincode',
    	'item_description',
    	'quantity',
    	'length',
    	'breadth',
    	'height',
    	'weight',
    	'date',
    	'return_address',
    	'value',
    	'shipper_name'
    ])
    {
    	try {
    		$mpdf = new Mpdf();
    		if ($params['payment'] == 'COD') {
    			$mpdf->WriteHTML('<!DOCTYPE html>
    				<html>
    				<head>
    				<title></title>
    				<style type="text/css">
    				td {
    					padding: 0;
    					margin: 0;
    				}
    				table.main-table{
    					width: 700px;
    					height: 600px;
    					border-collapse: collapse; 
    				}
    				</style>
    				</head>
    				<body>
    				<table class="main-table">
    				<tr style="margin-top: 50px;">
    				<td colspan="3" style="text-align: center;">
    				<strong>ECOM EXPRESS</strong>
    				</td>
    				</tr>
    				<tr style="height: 70px;">
    				<td style="vertical-align: middle;text-align: left; width: 150px;">
    				<span style="font-size: 18px; padding-left: 25px;"><strong>[</strong> ' . $params['payment'] . ' <strong>]</strong></span>
    				</td>
    				<td style="vertical-align: top;width: 400px; text-align: center">
    				<span style="font-size: 50px;font-weight: bold;"><barcode code=' . $params['awb_number'] . ' type="C128A" size="1.5" height="1.0" /></span>
    				</td>
    				<td style="vertical-align: middle;text-align: right; width: 150px;">
    				<span style="font-size: 18px; padding-left: 25px;"><strong>[</strong> ' . $params['state_code'] . '/' . $params['dc_code'] . ' <strong>]</strong></span>
    				</td>
    				</tr>
    				<tr style="margin-top: 50px;">
    				<td colspan="3" style="text-align: center;padding-bottom: 30px;">
    				<strong>' . $params['awb_number'] . '</strong>
    				</td>
    				</tr>
    				<tr style="margin-top: 50px;">
    				<td colspan="3" style="border-left: 2px solid; border-top: 2px solid; border-right: 2px solid; border-bottom: 2px solid;padding: 8px;">

    				<table>
    				<tr style="width: 700px;">
    				<td style="width:450px;">
    				<strong>Shipper : </strong>
    				<span>' . $params['shipper_name'] . '</span>
    				</td>
    				<td>
    				<strong>Order No : </strong>
    				<strong>' . $params['order_number'] . '</strong>
    				</td>
    				</tr>
    				</table>
    				</td>
    				</tr>
    				<tr>
    				<td colspan="3" style="padding: 3px;"></td>
    				</tr>

    				<tr>
    				<td colspan="3" style="border: 2px solid;">
    				<table style="width: 100%;border-collapse: collapse; height: 250px;">
    				<tr>
    				<td style="border-right: 2px solid; border-bottom: 2px solid;width: 50%; text-align: center;padding: 10px;">
    				<strong>Consignee Details</strong>
    				</td>
    				<td style="border-bottom: 2px solid; width: 50%; text-align: center;">
    				<strong>Order Details</strong>
    				</td>

    				</tr>
    				<tr>
    				<td style="border-right: 2px solid;vertical-align: top;padding-left: 20px;padding-top: 20px;">
    				<table>
    				<tr>
    				<td colspan="2">' . $params['name'] . '</td>
    				</tr>
    				<tr>
    				<td colspan="2">' . $params['customer_add1'] . ' , ' . $params['customer_add2'] . '</td>
    				</tr>
    				<tr>
    				<td colspan="2">' . $params['state_code'] . ' (' . $params['pincode'] . ')</td>					
    				</tr>
    				<tr>
    				<td colspan="2"><strong>Mobile number : ' . $params['phone'] . ' </strong></td>
    				</tr>
    				</table>
    				</td>
    				<td style="vertical-align: top; padding-left: 20px;padding-top: 20px;">
    				<table>
    				<tr>
    				<td><strong>Item Description : </strong></td>
    				<td>' . $params['item_description'] . '</td>
    				</tr>
    				<tr>
    				<td><strong>Total Quantity : </strong></td>
    				<td>' . $params['quantity'] . '</td>
    				</tr>
    				<tr>
    				<td><strong>Collectable Value : </strong></td>
    				<td>' . $params['value'] . '</td>
    				</tr>
    				<tr>
    				<td><strong>Dimension : </strong></td>
    				<td>' . $params['length'] . ' * ' . $params['breadth'] . ' * ' . $params['height'] . '</td>
    				</tr>
    				<tr>
    				<td><strong>Actual Weight : </strong></td>
    				<td>' . $params['weight'] . ' Kg</td>
    				</tr>
    				<tr>
    				<td><strong>Order Date : </strong></td>
    				<td>' . $params['date'] . '</td>
    				</tr>
    				</table>
    				</td>

    				</tr>
    				</table>
    				</td>
    				</tr>
    				<tr>
    				<td colspan="3" style="padding: 5px;"></td>
    				</tr>
    				<tr style="">
    				<td colspan="3" style="padding: 8px;border: 2px solid;text-align: center;">
    				<strong font-size: 18px;>IF UNDELIVERED RETURN TO :</strong>
    				</td>
    				</tr>
    				<tr style="height: 50px;">
    				<td colspan="3" style="padding: 12px;border: 2px solid;vertical-align: top;padding-left: 15px;">
    				' . $params['return_address'] . '
    				</td>
    				</tr>
    				</table>
    				</body>
    				</html>');
} elseif ($params['payment'] == 'PPD') {
	$mpdf->WriteHTML('<!DOCTYPE html>
		<html>
		<head>
		<title></title>
		<style type="text/css">
		td {
			padding: 0;
			margin: 0;
		}
		table.main-table{
			width: 700px;
			height: 600px;
			border-collapse: collapse; 
		}
		</style>
		</head>
		<body>
		<table class="main-table">
		<tr style="margin-top: 50px;">
		<td colspan="3" style="text-align: center;">
		<strong>ECOM EXPRESS</strong>
		</td>
		</tr>
		<tr style="height: 70px;">
		<td style="vertical-align: middle;text-align: left; width: 150px;">
		<span style="font-size: 18px; padding-left: 25px;"><strong>[</strong> ' . $params['payment'] . ' <strong>]</strong></span>
		</td>
		<td style="vertical-align: top;width: 400px; text-align: center">
		<span style="font-size: 50px;font-weight: bold;"><barcode code=' . $params['awb_number'] . ' type="C128A" size="1.5" height="1.0" /></span>
		</td>
		<td style="vertical-align: middle;text-align: right; width: 150px;">
		<span style="font-size: 18px; padding-left: 25px;"><strong>[</strong> ' . $params['state_code'] . '/' . $params['dc_code'] . ' <strong>]</strong></span>
		</td>
		</tr>
		<tr style="margin-top: 50px;">
		<td colspan="3" style="text-align: center;padding-bottom: 30px;">
		<strong>' . $params['awb_number'] . '</strong>
		</td>
		</tr>
		<tr style="margin-top: 50px;">
		<td colspan="3" style="border-left: 2px solid; border-top: 2px solid; border-right: 2px solid; border-bottom: 2px solid;padding: 8px;">

		<table>
		<tr style="width: 700px;">
		<td style="width:450px;">
		<strong>Shipper : </strong>
		<span>' . $params['shipper_name'] . '</span>
		</td>
		<td>
		<strong>Order no : </strong>
		<strong>' . $params['order_number'] . '</strong>
		</td>
		</tr>
		</table>
		</td>
		</tr>
		<tr>
		<td colspan="3" style="padding: 3px;"></td>
		</tr>

		<tr>
		<td colspan="3" style="border: 2px solid;">
		<table style="width: 100%;border-collapse: collapse; height: 250px;">
		<tr>
		<td style="border-right: 2px solid; border-bottom: 2px solid;width: 50%; text-align: center;padding: 10px;">
		<strong>Consignee Details</strong>
		</td>
		<td style="border-bottom: 2px solid; width: 50%; text-align: center;">
		<strong>Order Details</strong>
		</td>

		</tr>
		<tr>
		<td style="border-right: 2px solid;vertical-align: top;padding-left: 20px;padding-top: 20px;">
		<table>
		<tr>
		<td colspan="2">' . $params['name'] . '</td>
		</tr>
		<tr>
		<td colspan="2">' . $params['customer_add1'] . ' , ' . $params['customer_add2'] . '</td>
		</tr>
		<tr>
		<td colspan="2">' . $params['state_code'] . ' (' . $params['pincode'] . ')</td>					
		</tr>
		<tr>
		<td colspan="2"><strong>Mobile number : ' . $params['phone'] . ' </strong></td>
		</tr>
		</table>
		</td>
		<td style="vertical-align: top; padding-left: 20px;padding-top: 20px;">
		<table>
		<tr>
		<td><strong>Item Description : </strong></td>
		<td>' . $params['item_description'] . '</td>
		</tr>
		<tr>
		<td><strong>Total Quantity : </strong></td>
		<td>' . $params['quantity'] . '</td>
		</tr>
		<tr>
		<td><strong>Dimension : </strong></td>
		<td>' . $params['length'] . ' * ' . $params['breadth'] . ' * ' . $params['height'] . '</td>
		</tr>
		<tr>
		<td><strong>Actual Weight : </strong></td>
		<td>' . $params['weight'] . ' Kg</td>
		</tr>
		<tr>
		<td><strong>Order Date : </strong></td>
		<td>' . $params['date'] . '</td>
		</tr>
		</table>
		</td>

		</tr>
		</table>
		</td>
		</tr>
		<tr>
		<td colspan="3" style="padding: 5px;"></td>
		</tr>
		<tr style="">
		<td colspan="3" style="padding: 8px;border: 2px solid;text-align: center;">
		<strong font-size: 18px;>IF UNDELIVERED RETURN TO :</strong>
		</td>
		</tr>
		<tr style="height: 50px;">
		<td colspan="3" style="padding: 12px;border: 2px solid;vertical-align: top;padding-left: 15px;">
		' . $params['return_address'] . '
		</td>
		</tr>
		</table>
		</body>
		</html>');
}

$mpdf->Output(__DIR__ . '/../../../../public/media/labels/' . $params['awb_number'] . '.pdf', 'F');
} catch (\Exception $exception) {
	$context = [
		'path' => __METHOD__,
		'message' => $exception->getMessage()
	];
	$this->getDI()
	->get('log')
	->logContent(var_export($context, true), 7, "pdf-{$params['shipper_name']}.log");
}
}

public function tokens()
{
	$apiToken = $this->request->getHeader('Authorization');
	$merchant = Merchant::findFirst([
		'columns' => 'token',
		'conditions' => 'shop_url =?1',
		'bind' => [1 => App::getShop($apiToken)]
	]);
	$token = $merchant->token;
	return $token;
}

public static function getOrders($token, $apiToken, $orderId)
{
	$client = new Api([
		'api_key' => \App\Ecom\Models\App::APP_API_KEY,
		'api_secret' => \App\Ecom\Models\App::APP_SECRET_KEY,
		'myshopify_domain' => App::getShop($apiToken),
		'access_token' => $token
	]);

	$heler = new OrderService($client);
	sleep(1);
	$orders = $heler->get($orderId);
	return $orders;
}

public function getLocationsAction()
{
	$response = [
		'success' => false,
		'message' => 'Invalid Request',
		'data' => []
	];
	try {
		$apiToken = $this->request->getHeader('Authorization');
		$merchant = Merchant::findFirst([
			"columns" => "id",
			"conditions" => "shop_url = ?1",
			"bind" => [1 => App::getShop($apiToken)]
		]);
		$shopifyLocation = MerchantLocations::find(
			[
				'columns' => 'location_id,location_name',
				'conditions' => 'merchant_id = ?1',
				'bind' => [1 => $merchant->id]
			]
		)->toArray();
		$response = [
			'success' => true,
			'message' => 'locations found',
			'data' => $shopifyLocation
		];
	} catch (\Exception $exception) {
		$this->getDI()
		->get('log')
		->logContent(__METHOD__ . ' ' . $exception->getMessage(), 7, 'exception.log');
	}
	return $this->response->setJsonContent($response);
}

    /*public function cancelShipmentAction()
    {
        if ($this->request->isPost()) {
            $data = $this->request->getJsonRawBody();
            $response = '';
            $decision = MerchantSettings::carrierShow();
            $filterArray = [];
            for ($i = 0; $i < count($decision); $i++) {
                $filterArray[$decision[$i]['config_path']] = $decision[$i]['value'];
            }
            $obj = new \EcomExpressAPI\API($filterArray['user_name'], $filterArray['api_password']);

            $cancel = $obj->cancel((integer)$data);

            if (isset($cancel) and !empty($cancel)) {
                if ($cancel[0]['success'] == true) {

                    $id = MerchantShipment::findFirst([
                        'columns' => 'order_id,fulfillment_id',
                        'conditions' => 'tracking_number =?1',
                        'bind' => [1 => (integer)$data]
                    ]);
                    $idToUpdate = (integer)$data;
                    $data2 = MerchantShipment::cancelFullfilemnt((integer)$id['order_id'], (integer)$id['fulfillment_id']);

                    if ($data2['fulfillment']['status'] == 'cancelled') {

                        $merchantShipment = MerchantShipment::findFirst("tracking_number = '{$idToUpdate}'");
                        $merchantShipment->shipment_status = 'Cancelled';
                        $merchantShipment->update();
                    }

                    $response = ['success' => true, 'message' => 'Shipment Cancelled'];
                } else {
                    $response = ['success' => false, 'message' => 'Cant Cancell This Shipment'];
                }
                return json_encode($response, true);
            }
        }
        return '{}';
    }*/

    public function trackShipmentAction()
    {
    	try {
    		$response = ['success' => false, 'message' => 'Invalid Tracking Number'];
    		if ($this->request->get()) {
    			$awbNum = $this->request->getQuery('awb');
    			if (!empty($awbNum)) {
    				$apiToken = $this->request->getHeader('Authorization');
    				$decision = MerchantSettings::carrierShow(App::getShop($apiToken));
    				$filterArray = [];
    				for ($i = 0; $i < count($decision); $i++) {
    					$filterArray[$decision[$i]['config_path']] = $decision[$i]['value'];
    				}
    				$obj = new \EcomExpressAPI\API($filterArray['user_name'], $filterArray['api_password']);

    				if ($filterArray['development_mode'] == 1) {
    					$obj->developmentMode($filterArray['user_name'], $filterArray['api_password']);
    				}

    				$number = $awbNum;
    				$shipmentTracking = $obj->track($number);
    				if (isset($shipmentTracking) and !empty($shipmentTracking)) {
    					$response =
    					[
    						'success' => true,
    						'message' => 'Tracking Found',
    						'data' => $shipmentTracking
    					];
    				} else {
    					$response =
    					[
    						'success' => false,
    						'message' => 'Invalid Tracking Number'
    					];
    				}
    			} else {
    				$response =
    				[
    					'success' => false,
    					'message' => 'InValid Tracking Number'
    				];
    			}
    		}
    	} catch (\Exception $exception) {
    		$this->getDI()
    		->get('log')
    		->logContent(var_export($exception->getMessage(), true), 7, 'tracking.log');
    	}
    	return $this->response->setJsonContent($response);
    }
    public function refundsAction(){

    	try {

    		$apiToken = $this->request->getHeader('Authorization');
    		$domain = App::getShop($apiToken);
    		$response = '';
    		$merchant = Merchant::findFirst([
    			"columns" => "id",
    			"conditions" => "shop_url = ?1",
    			"bind" => [1 => $domain]
    		]);

    		$shopifyLocation = MerchantLocations::find(
    			[
    				'columns' => 'location_id,location_name',
    				'conditions' => 'merchant_id = ?1',
    				'bind' => [1 => $merchant->id]
    			]
    		)->toArray();

    		if ($extraAddresses = PickupAddresses::find([
    			'conditions' => "merchant_id = {$merchant->id}"
    		])) {
    			$extraAddresses->toArray();
    		}

    		$decision = MerchantSettings::carrierShow(App::getShop($apiToken));
    		$filterArray = [];
    		for ($i = 0; $i < count($decision); $i++) {
    			$filterArray[$decision[$i]['config_path']] = $decision[$i]['value'];
    		}
    		foreach (self::REQUIRED_FIELDS as $REQUIRED_FIELD) {
    			if (
    				$REQUIRED_FIELD == 'development_mode' or $REQUIRED_FIELD == 'include_handling_fee'
    				or $REQUIRED_FIELD == 'show_frontend' or $REQUIRED_FIELD == 'shipping_method'
    			) {
    				if ((string)$filterArray[$REQUIRED_FIELD] == '0' or (string)$filterArray[$REQUIRED_FIELD] == '1') {
    				} else {
    					return $this->response->setJsonContent(
    						[
    							'success' => false,
    							'message' => 'Unsaved Required Settings'
    						]
    					);
    				}
    			} elseif (empty($filterArray[$REQUIRED_FIELD])) {
    				return $this->response->setJsonContent(
    					[
    						'success' => false,
    						'message' => 'Unsaved Required Settings'
    					]
    				);
    			}
    		}
    		$defaultAddress = [];

    		$defaultAddress = [
    			'pickup_name' => $filterArray['pickup_name'],
    			'pickup_address_line1' => $filterArray['pickup_address_line1'],
    			'pickup_address_line2' => $filterArray['pickup_address_line2'],
    			'pickup_pincode' => $filterArray['pickup_pincode'],
    			'pickup_phone' => $filterArray['pickup_phone'],
    			'pickup_mobile' => $filterArray['pickup_phone'],
    			'pickup_type' => $filterArray['pickup_type']
    		];

    		if (isset($merchant) and !empty($merchant)) {
    			if ($this->request->isGet()) {
    				$orders = new MerchantOrders();
    				$filters = ['merchant_id' => $merchant->id, 'order_status' => 'refunded'];

    				$filtersEncoded = $this->request->getQuery('filters');
    				$IdEncoded = $this->request->getQuery('id');
    				$offset = $this->request->getQuery('page');
    				if (isset($filtersEncoded) and !empty($filtersEncoded)) {
    					$filtersArray = json_decode(base64_decode($filtersEncoded), true);

    					if (is_array($filtersArray)) {
    						$filters = array_merge($filters, $filtersArray);
    					}
    				} elseif (isset($IdEncoded) and !empty($IdEncoded)) {
    					$id = json_decode($IdEncoded, true);
    					$token = $this->tokens();
    					$id_to_ship = MerchantOrders::findFirst([
    						'columns' => 'order_id,order_refund_id',
    						'conditions' => 'id =?1',
    						'bind' => [1 => $id]
    					]);
    					if (isset($id_to_ship) and !empty($id_to_ship)) {
    						$client = new Api([
    							'api_key' => \App\Ecom\Models\App::APP_API_KEY,
    							'api_secret' => \App\Ecom\Models\App::APP_SECRET_KEY,
    							'myshopify_domain' => App::getShop($apiToken),
    							'access_token' => $token
    						]);


    						//calling refund apis
    						$refundOrders = new RefundService($client);

    						$orderId = (int)$id_to_ship->order_id;
    						$refundId=(int)$id_to_ship->order_refund_id;
    						$data = $refundOrders->get($orderId,$refundId);

    						$data = json_encode($data, true);
    						$newvariable = json_decode($data, true);
    						$newvariable = json_decode($newvariable, true);
    						foreach ($newvariable['refund_line_items'] as $key => $value) {
    							
    							if ($shipmentData = MerchantProducts::findFirst(
    								[
    									'columns' => 'category,hsn_code,length,breadth,height,location_id',
    									'conditions' => 'product_id =?1 AND merchant_id =?2 AND variant_id =?3',
    									'bind' =>
    									[
    										1 => $newvariable['refund_line_items'][$key]['line_item']['product_id'],
    										2 => $merchant->id,
    										3 => $newvariable['refund_line_items'][$key]['line_item']['variant_id']
    									]
    								]
    							)) {
    								$newData = $newvariable['refund_line_items'][$key]['line_item'];
    								$newData['hsn_code'] = $shipmentData->hsn_code;
                                    $newData['category'] = $shipmentData->category;
                                    $newData['length'] = $shipmentData->length;
                                    $newData['breadth'] = $shipmentData->breadth;
                                    $newData['height'] = $shipmentData->height;
                                    $newData['location_id'] = $shipmentData->location_id;
    								$newvariable['line_items'][$key] = json_encode($newData);

    							}
    							else{
    								$newData = $newvariable['refund_line_items'][$key]['line_item'];
    								$newvariable['line_items'][$key] = json_encode($newData);
    							}
    						}

    						$shippedData = MerchantShipment::shippedData($orderId);

    						$orders = new OrderService($client);

    						$data = $orders->get($orderId);

    							

    						$data = json_encode($data, true);
    						$data=json_decode($data,true);
    						$data=json_decode($data,true);
    						$shipping_address=$data['shipping_address'];
    						$response =
                            [
                                'success' => true,
                                'message' => 'Refunds Found',
                                'data' =>
                                [
                                    'refund_data' => $newvariable,
                                    'shipped_data' => $shippedData,
                                    'extra_addresses' => $extraAddresses,
                                    'default_address' => $defaultAddress,
                                    'locations' => $shopifyLocation,
                                    'shipping_address'=>$shipping_address,
                                ]
                            ];
    					}else {
    						$response =
    						[
    							'success' => false,
    							'message' => 'No Data Found By This ID',
    							'count' => 0,
    							'data' => array(
    								'order_data' => array(),
    								'shipped_data' => array(),
    								'extra_addresses' => $extraAddresses,
    								'default_address' => $defaultAddress,
    								'locations' => $shopifyLocation
    							),
    						];
    					}

    					return $this->response->setJsonContent($response);
    				}
    			}

    		}
    	}catch (\Exception $exception) {
    		print_r($exception->getMessage());
    		die;
    		$context = [
    			'path' => __METHOD__,
    			'message' => $exception->getMessage(),
    			'order_data' => $data,
    			'order_id' => $orderId
    		];
    		$response = ['success' => false, 'message' => 'Order Not Found', 'count' => 0, 'data' => array(
    			'order_data' => array(),
    			'shipped_data' => array(),
    			'extra_addresses' => $extraAddresses,
    			'default_address' => $defaultAddress,
    			'locations' => $shopifyLocation
    		)];
    		$this->getDI()
    		->get('log')
    		->logContent(
    			var_export($exception->getMessage(), true),
    			7,
    			"orders-{$domain}.log",
    			$context
    		);
    	}
    }
    public function ordersAction()
    {
    	try {
    		$apiToken = $this->request->getHeader('Authorization');
    		$domain = App::getShop($apiToken);
    		$response = '';
    		$merchant = Merchant::findFirst([
    			"columns" => "id",
    			"conditions" => "shop_url = ?1",
    			"bind" => [1 => $domain]
    		]);

    		$shopifyLocation = MerchantLocations::find(
    			[
    				'columns' => 'location_id,location_name',
    				'conditions' => 'merchant_id = ?1',
    				'bind' => [1 => $merchant->id]
    			]
    		)->toArray();

    		if ($extraAddresses = PickupAddresses::find([
    			'conditions' => "merchant_id = {$merchant->id}"
    		])) {
    			$extraAddresses->toArray();
    		}

    		$decision = MerchantSettings::carrierShow(App::getShop($apiToken));
    		$filterArray = [];

    		for ($i = 0; $i < count($decision); $i++) {
    			$filterArray[$decision[$i]['config_path']] = $decision[$i]['value'];
    		}

    		foreach (self::REQUIRED_FIELDS as $REQUIRED_FIELD) {
    			if (
    				$REQUIRED_FIELD == 'development_mode' or $REQUIRED_FIELD == 'include_handling_fee'
    				or $REQUIRED_FIELD == 'show_frontend' or $REQUIRED_FIELD == 'shipping_method'
    			) {
    				if ((string)$filterArray[$REQUIRED_FIELD] == '0' or (string)$filterArray[$REQUIRED_FIELD] == '1') {
    				} else {
    					return $this->response->setJsonContent(
    						[
    							'success' => false,
    							'message' => 'Unsaved Required Settings'
    						]
    					);
    				}
    			} elseif (empty($filterArray[$REQUIRED_FIELD])) {
    				return $this->response->setJsonContent(
    					[
    						'success' => false,
    						'message' => 'Unsaved Required Settings'
    					]
    				);
    			}
    		}

    		$defaultAddress = [];
    		$defaultAddress = [
    			'pickup_name' => $filterArray['pickup_name'],
    			'pickup_address_line1' => $filterArray['pickup_address_line1'],
    			'pickup_address_line2' => $filterArray['pickup_address_line2'],
    			'pickup_pincode' => $filterArray['pickup_pincode'],
    			'pickup_phone' => $filterArray['pickup_phone'],
    			'pickup_mobile' => $filterArray['pickup_phone'],
    			'pickup_type' => $filterArray['pickup_type']
    		];

    		if (isset($merchant) and !empty($merchant)) {

    			if ($this->request->isGet()) {
    				$orders = new MerchantOrders();
    				$filters = ['merchant_id' => $merchant->id, 'order_status' => 'transit'];

    				$filtersEncoded = $this->request->getQuery('filters');
    				$IdEncoded = $this->request->getQuery('id');
    				$offset = $this->request->getQuery('page');


    				if (isset($filtersEncoded) and !empty($filtersEncoded)) {
    					$filtersArray = json_decode(base64_decode($filtersEncoded), true);

    					if (is_array($filtersArray)) {
    						$filters = array_merge($filters, $filtersArray);
    					}
    				} elseif (isset($IdEncoded) and !empty($IdEncoded)) {

    					$id = json_decode($IdEncoded, true);

    					$token = $this->tokens();

    					$id_to_ship = MerchantOrders::findFirst([
    						'columns' => 'order_id',
    						'conditions' => 'id =?1',
    						'bind' => [1 => $id]
    					]);
                        //print_r($id_to_ship->order_id);die("HELLO");
    					if (isset($id_to_ship) and !empty($id_to_ship)) {
    						$client = new Api([
    							'api_key' => \App\Ecom\Models\App::APP_API_KEY,
    							'api_secret' => \App\Ecom\Models\App::APP_SECRET_KEY,
    							'myshopify_domain' => App::getShop($apiToken),
    							'access_token' => $token
    						]);
    						$orders = new OrderService($client);

    						$orderId = (int)$id_to_ship->order_id;

    						sleep(1);

    						$data = $orders->get($orderId);
//                            print_r($data); die("pdyef");
    						$data = json_encode($data, true);
    						$newvariable = json_decode($data, true);
    						$newvariable = json_decode($newvariable, true);
    						foreach ($newvariable['line_items'] as $key => $value) {
    							if ($shipmentData = MerchantProducts::findFirst(
    								[
    									'columns' => 'category,hsn_code,length,breadth,height,location_id',
    									'conditions' => 'product_id =?1 AND merchant_id =?2 AND variant_id =?3',
    									'bind' =>
    									[
    										1 => json_decode($newvariable['line_items'][$key], true)['product_id'],
    										2 => $merchant->id,
    										3 => json_decode($newvariable['line_items'][$key], true)['variant_id']
    									]
    								]
    							)) {
    								$newData = json_decode($newvariable['line_items'][$key], true);
    								$newData['hsn_code'] = $shipmentData->hsn_code;
    								$newData['category'] = $shipmentData->category;
    								$newData['length'] = $shipmentData->length;
    								$newData['breadth'] = $shipmentData->breadth;
    								$newData['height'] = $shipmentData->height;
    								$newData['location_id'] = $shipmentData->location_id;
    								$newvariable['line_items'][$key] = json_encode($newData);
    							}
    						}
    						$shippedData = MerchantShipment::shippedData($orderId);
    						$response =
    						[
    							'success' => true,
    							'message' => 'Orders Found',
    							'data' =>
    							[
    								'order_data' => $newvariable,
    								'shipped_data' => $shippedData,
    								'extra_addresses' => $extraAddresses,
    								'default_address' => $defaultAddress,
    								'locations' => $shopifyLocation
    							]
    						];
    					} else {
    						$response =
    						[
    							'success' => false,
    							'message' => 'No Data Found By This ID',
    							'count' => 0,
    							'data' => array(
    								'order_data' => array(),
    								'shipped_data' => array(),
    								'extra_addresses' => $extraAddresses,
    								'default_address' => $defaultAddress,
    								'locations' => $shopifyLocation
    							),
    						];
    					}

    					return $this->response->setJsonContent($response);
    				}
    				if (count($orders->getOrders($filters, $offset)) != 0) {

    					$ordersData = $orders->getOrders($filters, $offset);
    					$response =
    					[
    						'success' => true,
    						'message' => 'Success',
    						'data' => $ordersData['orders'],
    						'count' => $ordersData['total_counts']
    					];
    				} else {
    					$response =
    					[
    						'success' => false,
    						'message' => 'No Orders found',
    						'count' => 0,
    						'data' => array(
    							'order_data' => array(),
    							'shipped_data' => array(),
    							'extra_addresses' => $extraAddresses,
    							'default_address' => $defaultAddress,
    							'locations' => $shopifyLocation
    						)
    					];
    				}

    				return $this->response->setJsonContent($response);
    			}
    		}
    	} catch (\Exception $exception) {
    		print_r($exception->getMessage());
    		die;
    		$context = [
    			'path' => __METHOD__,
    			'message' => $exception->getMessage(),
    			'order_data' => $data,
    			'order_id' => $orderId
    		];
    		$response = ['success' => false, 'message' => 'Order Not Found', 'count' => 0, 'data' => array(
    			'order_data' => array(),
    			'shipped_data' => array(),
    			'extra_addresses' => $extraAddresses,
    			'default_address' => $defaultAddress,
    			'locations' => $shopifyLocation
    		)];
    		$this->getDI()
    		->get('log')
    		->logContent(
    			var_export($exception->getMessage(), true),
    			7,
    			"orders-{$domain}.log",
    			$context
    		);
    	}
    	return $this->response->setJsonContent($response);
    }

    public function shipmentsAction()
    {
    	$apiToken = $this->request->getHeader('Authorization');
    	$response =
    	[
    		'success' => false,
    		'message' => 'No Orders found',
    		'count' => 0
    	];
    	$merchant = Merchant::findFirst([
    		"columns" => "id",
    		"conditions" => "shop_url = ?1",
    		"bind" => [1 => App::getShop($apiToken)]
    	]);

    	$decision = MerchantSettings::carrierShow(App::getShop($apiToken));
    	$filterArray = [];
    	for ($i = 0; $i < count($decision); $i++) {
    		$filterArray[$decision[$i]['config_path']] = $decision[$i]['value'];
    	}

    	foreach (self::REQUIRED_FIELDS as $REQUIRED_FIELD) {
    		if (
    			$REQUIRED_FIELD == 'development_mode' or $REQUIRED_FIELD == 'include_handling_fee'
    			or $REQUIRED_FIELD == 'show_frontend' or $REQUIRED_FIELD == 'shipping_method'
    		) {
    			if (isset($filterArray[$REQUIRED_FIELD]) and ((string)$filterArray[$REQUIRED_FIELD] == '0' or (string)$filterArray[$REQUIRED_FIELD] == '1')) {
    				continue;
    			} else {
    				return $this->response->setJsonContent(
    					[
    						'success' => false,
    						'message' => 'Unsaved Required Settings'
    					]
    				);
    			}
    		} elseif (empty($filterArray[$REQUIRED_FIELD])) {
    			return $this->response->setJsonContent(
    				[
    					'success' => false,
    					'message' => 'Unsaved Required Settings'
    				]
    			);
    		}
    	}

    	if (isset($merchant) and !empty($merchant)) {
    		if ($this->request->isGet()) {
    			$filters = ['merchant_id' => $merchant->id];
    			$shippedOrders = new MerchantShipment();

    			$filtersEncoded = $this->request->getQuery('filters');
    			$offset = $this->request->getQuery('page');
    			if (isset($filtersEncoded) and !empty($filtersEncoded)) {
    				$filtersArray = json_decode(base64_decode($filtersEncoded), true);
    				if (is_array($filtersArray)) {
    					$filters = array_merge($filters, $filtersArray);
    				}
    			}
    			if (count($shippedOrders->getShippedOrders($filters, $offset)) != 0) {
    				$ordersData = $shippedOrders->getShippedOrders($filters, $offset);
    				$response =
    				[
    					'success' => true,
    					'message' => 'Success',
    					'data' => $ordersData['orders'],
    					'count' => $ordersData['total_counts']
    				];
    			}
    		}
    	}
    	return $this->response->setJsonContent($response);
    }

    public function productsAction()
    {
    	$apiToken = $this->request->getHeader('Authorization');
    	$response =
    	[
    		'success' => false,
    		'message' => 'No Orders found',
    		'count' => 0
    	];

    	$merchant = Merchant::findFirst([
    		"columns" => "id",
    		"conditions" => "shop_url = ?1",
    		"bind" => [1 => App::getShop($apiToken)]
    	]);

    	if (isset($merchant->id) and !empty($merchant->id)) {
    		if ($this->request->isGet()) {
    			$products = new MerchantProducts();
    			$filters = ['merchant_id' => $merchant->id];
    			$filtersEncoded = $this->request->getQuery('filters');
    			$offset = $this->request->getQuery('page');
    			if (isset($filtersEncoded) and !empty($filtersEncoded)) {
    				$filtersArray = json_decode(base64_decode($filtersEncoded), true);
    				if (is_array($filtersArray)) {
    					$filters = array_merge($filters, $filtersArray);
    				}
    			}
    			if (count($products->getProducts($filters, $offset)) != 0) {
    				$productData = $products->getProducts($filters, $offset);
    				$response =
    				[
    					'success' => true,
    					'message' => 'Success',
    					'data' => $productData['orders'],
    					'count' => $productData['total_counts']
    				];
    			}
    		}
    	}
    	return $this->response->setJsonContent($response);
    }

    public function pinCodesAction()
    {
    	if ($this->request->isGet()) {
    		$pins = new EcomPincodes();
    		$filters = [];
    		$filtersEncoded = $this->request->getQuery('filters');
    		$offset = $this->request->getQuery('page');
    		if (isset($filtersEncoded) and !empty($filtersEncoded)) {
    			$filtersArray = json_decode(base64_decode($filtersEncoded), true);
    			if (is_array($filtersArray)) {
    				$filters = array_merge($filters, $filtersArray);
    			}
    		}
    		if (count($pins->getPins($filters, $offset)) != 0) {

    			$data = $pins->getPins($filters, $offset);

    			$response =
    			[
    				'success' => true,
    				'message' => 'Success',
    				'data' => $data['pincodes'],
    				'page' => $offset,
    				'count' => $data['total_count']
    			];
    		} else {
    			$response =
    			[
    				'success' => false,
    				'message' => 'No Pincodes found',
    				'count' => 0
    			];
    		}

    		return $this->response->setJsonContent($response);
    	}

    	return '{}';
    }

    public function selectedPinCodesAction()
    {
    	$apiToken = $this->request->getHeader('Authorization');

    	if ($this->request->isGet()) {

    		$filters = [];
    		$pins = new MerchantPincodes();

    		$filtersEncoded = $this->request->getQuery('filters');
    		$offset = $this->request->getQuery('page');
    		if (isset($filtersEncoded) and !empty($filtersEncoded)) {
    			$filtersArray = json_decode(base64_decode($filtersEncoded), true);
    			if (is_array($filtersArray)) {
    				$filters = array_merge($filters, $filtersArray);
    			}
    		}
    		if (count($pins->getPins($apiToken, $filters, $offset)) != 0) {

    			$data = $pins->getPins($apiToken, $filters, $offset);

    			$response =
    			[
    				'success' => true,
    				'message' => 'Success',
    				'data' => $data['pincodes'],
    				'count' => $data['total_count'],
    				'page' => $offset
    			];
    		} else {
    			$response =
    			[
    				'success' => false,
    				'message' => 'There Are No Selected Pincodes',
    				'count' => 0
    			];
    		}
    		return json_encode($response);
    	}
    	return '{}';
    }


    public function savepincodesAction()
    {
    	$apiToken = $this->request->getHeader('Authorization');
    	$resp = true;
    	$response = [];
    	if ($this->request->isPost()) {
    		$merchant = Merchant::findFirst([
    			"columns" => "id",
    			"conditions" => "shop_url = ?1",
    			"bind" => [1 => App::getShop($apiToken)]
    		]);


    		$data = $this->request->getRawBody();
    		$data = json_decode($data, true);


    		foreach ($data as $value) {

    			$entry = MerchantPincodes::findFirst([
    				'columns' => "pincode_id",
    				'conditions' => "merchant_id = '{$merchant->id}' AND pincode_id = '{$value['id']}' "
    			]);

    			if (isset($entry) and !empty($entry)) {
    				$resp = false;
    			} else {
    				$merchant_pincodes = new MerchantPincodes();
    				$resp = $merchant_pincodes->save([
    					'merchant_id' => $merchant->id,
    					'pincode_id' => $value['id']
    				]);
    			}
    		}
    	}
    	if ($resp == true) {

    		$response =
    		[
    			'success' => true,
    			'message' => 'Pincodes Saved Successfully'
    		];
    	} elseif ($resp == false) {
    		$response =
    		[
    			'success' => false,
    			'message' => 'Pincodes already saved'
    		];
    	}
    	return $this->response->setJsonContent($response);
    }

    public function deletepincodesAction()
    {
    	if ($this->request->isPost()) {
    		$data = $this->request->getRawBody();
    		$data = json_decode($data, true);
    		foreach ($data as $datum) {
    			$pincode_id = MerchantPincodes::find("pincode_id = " . $datum['id'] . " ");
    			foreach ($pincode_id as $id) {
    				$id->delete();
    			}
    		}
    		$response =
    		[
    			'success' => true,
    			'message' => 'Pincodes Deleted Successfully'
    		];
    		return json_encode($response);
    	}
    	return '{}';
    }

    public function markAsPaidAction()
    {

    	if ($this->request->isPost()) {
    		$data = $this->request->getRawBody();
    		$reqdata = json_decode($data, true);
    		$apiToken = $this->request->getHeader('Authorization');
    		$response = '';
    		$token = $this->tokens();
    		$client = new Api([
    			'api_key' => \App\Ecom\Models\App::APP_API_KEY,
    			'api_secret' => \App\Ecom\Models\App::APP_SECRET_KEY,
    			'myshopify_domain' => App::getShop($apiToken),
    			'access_token' => $token
    		]);

    		$helper = new TransactionService($client);
    		$transction = new Transaction();
    		$transction->amount = $reqdata['total'];
    		$transction->kind = 'capture';
    		$helper->create($transction, (int)$reqdata['order_id']);

    		return json_encode(
    			[
    				'success' => true,
    				'message' => 'Payment Successful'
    			]
    		);
    	}

    	return json_encode(
    		[
    			'success' => true,
    			'message' => 'Payment Unsuccessful'
    		]
    	);
    }
    public function merchantStatusCheckAction(){
    	if ($this->request->isGet()) {
    		$apiToken = $this->request->getHeader('Authorization');

    		$merchant = Merchant::findFirst([
    			"columns" => "id",
    			"conditions" => "shop_url = ?1",
    			"bind" => [1 => App::getShop($apiToken)]
    		]);
    		$accessMerchant = Merchant::findFirst([
    			"columns" => "merchantStatus_check,email,owner_name",
    			"conditions" => "shop_url = ?1",
    			"bind" => [1 => App::getShop($apiToken)]
    		]);
    	}
    	return $this->response->setJsonContent(
    		[
    			'success' => true,
    			'message' => 'Found',
    			'data' => $accessMerchant
    		]
    	);
    }
    public function merchantStatusCheckUpdateAction(){
    	if ($this->request->isGet()) {
    		$apiToken = $this->request->getHeader('Authorization');
    		$shop = App::getShop($apiToken);

    		$merchant = Merchant::findFirst("shop_url = '{$shop}'");

    		$merchantCheck = Merchant::findFirst([
    			"columns" => "id,token,merchantStatus_check",
    			"conditions" => "shop_url = ?1",
    			"bind" => [1 => App::getShop($apiToken)]
    		]);

    		$client = new Api([
    			'api_key' => \App\Ecom\Models\App::APP_API_KEY,
    			'api_secret' => \App\Ecom\Models\App::APP_SECRET_KEY,
    			'myshopify_domain' => App::getShop($apiToken),
    			'access_token' => $merchantCheck->token
    		]);
    		$helper = new ShopService($client);
    		$shopdetailsFromShopify=$helper->get();
    		$shopdetailsFromShopify = json_encode($shopdetailsFromShopify);
    		$shopdetailsFromShopify = json_decode($shopdetailsFromShopify, true);
    		$shopdetailsFromShopify = json_decode($shopdetailsFromShopify, true);

    		$email=$shopdetailsFromShopify['email'];
    		$name=$shopdetailsFromShopify['name'];
    		$phone=$shopdetailsFromShopify['phone'];
    		$data['data']=[
    			'name'=>$name,
    			'phone'=>$phone,
    			'email'=>$email,
    		];
    		if (isset($merchant)) {
    			$merchant->merchantStatus_check = TRUE;
    			$merchant->update();

    		}

    		$response = ['success' => true,
    		'data'=>$data,
    	];
    }

    return json_encode($response);

}
public function introjsAction()
{
	$introData = [
		'data' => []
	];

	if ($this->request->isGet()) {
		$apiToken = $this->request->getHeader('Authorization');
		$decision = MerchantSettings::carrierShow(App::getShop($apiToken));

		$filterArray = [];
		for ($i = 0; $i < count($decision); $i++) {
			$filterArray[$decision[$i]['config_path']] = $decision[$i]['value'];
		}


		foreach (self::REQUIRED_FIELDS as $REQUIRED_FIELD) {
			if (
				$REQUIRED_FIELD == 'development_mode' or $REQUIRED_FIELD == 'include_handling_fee'
				or $REQUIRED_FIELD == 'show_frontend' or $REQUIRED_FIELD == 'shipping_method'
			) {
				if (isset($filterArray[$REQUIRED_FIELD]) and ((string)$filterArray[$REQUIRED_FIELD] == '0' or (string)$filterArray[$REQUIRED_FIELD] == '1')) {
				} else {

					return $this->response->setJsonContent(
						[
							'success' => false,
							'message' => 'Unsaved Required Settings'
						]
					);
				}
			} elseif (empty($filterArray[$REQUIRED_FIELD])) {
				return $this->response->setJsonContent(
					[
						'success' => false,
						'message' => 'Unsaved Required Settings'
					]
				);
			}
		}

		$merchant = Merchant::findFirst([
			"columns" => "id",
			"conditions" => "shop_url = ?1",
			"bind" => [1 => App::getShop($apiToken)]
		]);
		$accessMerchant = Merchant::findFirst([
			"columns" => "introjs_values",
			"conditions" => "shop_url = ?1",
			"bind" => [1 => App::getShop($apiToken)]
		]);
		if (is_null($accessMerchant->introjs_values)) {
			$introData = [
				'merchant_id' => $merchant->id,
				'data' => []
			];
		} else {
			$introData = [
				'merchant_id' => $merchant->id,
				'data' => $accessMerchant->introjs_values
			];
		}
	}

	return $this->response->setJsonContent(
		[
			'success' => true,
			'message' => 'Found',
			'data' => $introData
		]
	);
}

public function insertintrojsDataAction()
{

	$apiToken = $this->request->getHeader('Authorization');
	$shop = App::getShop($apiToken);


	if ($this->request->isPost()) {
		$data = $this->request->getRawBody();
		$reqdata = json_decode($data, true);

		$merchant = Merchant::findFirst("shop_url = '{$shop}'");

		if (isset($merchant)) {
			$merchant->introjs_values = $data;
			$merchant->update();
			$resp = true;
		}
	}

	if ($resp == true) {
		$response = ['success' => true];
	} else {
		$response = ['success' => false, 'message' => 'Failed'];
	}
	return json_encode($response);
}


public function overviewAction()
{
	$overview = [
		'data' => []
	];

	if ($this->request->isGet()) {
		$apiToken = $this->request->getHeader('Authorization');
		$decision = MerchantSettings::carrierShow(App::getShop($apiToken));

		$filterArray = [];
		for ($i = 0; $i < count($decision); $i++) {
			$filterArray[$decision[$i]['config_path']] = $decision[$i]['value'];
		}


		foreach (self::REQUIRED_FIELDS as $REQUIRED_FIELD) {
			if (
				$REQUIRED_FIELD == 'development_mode' or $REQUIRED_FIELD == 'include_handling_fee'
				or $REQUIRED_FIELD == 'show_frontend' or $REQUIRED_FIELD == 'shipping_method'
			) {
				if (isset($filterArray[$REQUIRED_FIELD]) and ((string)$filterArray[$REQUIRED_FIELD] == '0' or (string)$filterArray[$REQUIRED_FIELD] == '1')) {
				} else {

					return $this->response->setJsonContent(
						[
							'success' => false,
							'message' => 'Unsaved Required Settings'
						]
					);
				}
			} elseif (empty($filterArray[$REQUIRED_FIELD])) {
				return $this->response->setJsonContent(
					[
						'success' => false,
						'message' => 'Unsaved Required Settings'
					]
				);
			}
		}

		$merchant = Merchant::findFirst([
			"columns" => "id",
			"conditions" => "shop_url = ?1",
			"bind" => [1 => App::getShop($apiToken)]
		]);

		$cache = $this->getDI()->get('cache');

		$overview = $cache->get('overview_' . $merchant->id);

		if (!isset($overview) or empty($overview)) {
			$totalOrders = MerchantOrders::find([
				'columns' => 'id',
				'conditions' => 'merchant_id =?1',
				'bind' => [1 => $merchant->id]
			])->toArray();
			$totalShipped = MerchantShipment::find([
				'columns' => 'id',
				'conditions' => 'merchant_id =?1 AND ecom_status =?2',
				'bind' => [1 => $merchant->id, 2 => 'Outscan']
			])->toArray();

			$totalUnShipped = MerchantOrders::find([
				'columns' => 'id',
				'conditions' => 'fulfillment_status =?1 AND merchant_id =?2 AND order_status =?3',
				'bind' => [1 => 'unfulfilled', 2 => $merchant->id, 3 => 'transit']
			])->toArray();

			$totalDelivered = MerchantShipment::find([
				'columns' => 'id',
				'conditions' => 'merchant_id =?1 AND ecom_status =?2',
				'bind' => [1 => $merchant->id, 2 => 'Delivered / Closed']
			])->toArray();

			$totalOrdersMonthWise = $this->db->query('SELECT YEAR(order_created_at) AS year, MONTHNAME(order_created_at) AS month, COUNT(DISTINCT id) AS count
				FROM merchant_orders WHERE  merchant_id = ' . $merchant->id . ' AND order_created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
				GROUP BY year, month ORDER BY month DESC');

			$totalShippedOrdersMonthWise = $this->db->query('SELECT YEAR(created_at) AS year, MONTHNAME(created_at) AS month, COUNT(DISTINCT id) AS count
				FROM merchant_shipments WHERE merchant_shipments.ecom_status = "shipped" AND order_fulfilled_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) AND merchant_id = ' . $merchant->id . '
				GROUP BY year, month ORDER BY month DESC');

			$totalDeliveredOrdersMonthWise = $this->db->query('SELECT YEAR(order_fulfilled_at) AS year, MONTHNAME(order_fulfilled_at) AS month, COUNT(DISTINCT id) AS count
				FROM merchant_orders WHERE merchant_orders.ecom_status = "Delivered" AND order_fulfilled_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) AND merchant_id = ' . $merchant->id . '
				GROUP BY year, month ORDER BY month DESC');

			$totalOrdersWeekWise = $this->db->query('SELECT YEAR(order_created_at) AS year, MONTHNAME(order_created_at) AS month, DAYNAME(order_created_at) AS day ,COUNT(DISTINCT id) AS count FROM merchant_orders
				WHERE order_created_at BETWEEN date_sub(now(),INTERVAL 1 WEEK) AND now() AND merchant_id = ' . $merchant->id . ' GROUP BY year,month,day ORDER BY day DESC');

			$totalShippedOrdersWeekWise = $this->db->query('SELECT YEAR(created_at) AS year, MONTHNAME(created_at) AS month, DAYNAME(created_at) AS day ,COUNT(DISTINCT id) AS count FROM merchant_shipments
				WHERE order_fulfilled_at BETWEEN date_sub(now(),INTERVAL 1 WEEK) AND now() AND merchant_shipments.ecom_status = "shipped" AND merchant_id = ' . $merchant->id . ' GROUP BY year,month,day ORDER BY day DESC
				');

			$totalDeliveredOrdersWeekWise = $this->db->query('SELECT YEAR(created_at) AS year, MONTHNAME(created_at) AS month, DAYNAME(created_at) AS day ,COUNT(DISTINCT id) AS count FROM merchant_shipments
				WHERE order_fulfilled_at BETWEEN date_sub(now(),INTERVAL 1 WEEK) AND now() AND merchant_shipments.ecom_status = "Delivered" AND merchant_id = ' . $merchant->id . ' GROUP BY year,month,day ORDER BY day DESC
				');

			$overview =
			[
				'merchant_id' => $merchant->id,
				'data' =>
				[
					'total_orders' => count($totalOrders),
					'total_shipped' => count($totalShipped),
					'total_pending' => count($totalUnShipped),
					'total_delivered' => count($totalDelivered),
					'total_orders_month_wise' => $totalOrdersMonthWise->fetchAll(),
					'total_shipped_orders_month_wise' => $totalShippedOrdersMonthWise->fetchAll(),
					'total_delivered_orders_month_wise' => $totalDeliveredOrdersMonthWise->fetchAll(),
					'total_orders_week_wise' => $totalOrdersWeekWise->fetchAll(),
					'total_shipped_orders_week_wise' => $totalShippedOrdersWeekWise->fetchAll(),
					'total_delivered_orders_week_wise' => $totalDeliveredOrdersWeekWise->fetchAll()
				]
			];
			$cache->set('overview_' . $merchant->id, $overview);
		}
	}
	return $this->response->setJsonContent(
		[
			'success' => true,
			'message' => 'Found',
			'data' => $overview['data']
		]
	);
}

public function getLabelAction()
{
	if ($this->request->isGet()) {
		$awbNumber = $this->request->getQuery('awb');
		$path = __DIR__ . '/../../../../public/media/labels/' . $awbNumber . '.pdf';
		if (file_exists($path)) {
			$this->response->setHeader('Content-type', 'application/pdf');
			return $this->response->setFileToSend($path, $awbNumber . '.pdf', false);
		} else {

			$packageData = MerchantShipment::findFirst(
				[
					'columns' => 'label_data',
					'conditions' => 'tracking_number =?1',
					'bind' => [1 => $awbNumber]
				]
			);

			$packageData = unserialize($packageData['label_data']);
			if (isset($packageData) and !empty($packageData)) {
				$labelParams['name'] = $packageData['name'];
				$labelParams['awb_number'] = $packageData['awb_number'];
				$labelParams['order_number'] = $packageData['order_number'];
				$labelParams['payment'] = $packageData['payment'];
				$labelParams['state_code'] = $packageData['state_code'];
				$labelParams['dc_code'] = $packageData['dc_code'];
				$labelParams['phone'] = $packageData['phone'];
				$labelParams['customer_add1'] = $packageData['customer_add1'];
				$labelParams['customer_add2'] = $packageData['customer_add2'];
				$labelParams['pincode'] = $packageData['pincode'];
				$labelParams['item_description'] = $packageData['item_description'];
				$labelParams['quantity'] = $packageData['quantity'];
				$labelParams['length'] = $packageData['length'];
				$labelParams['breadth'] = $packageData['breadth'];
				$labelParams['height'] = $packageData['height'];
				$labelParams['value'] = $packageData['value'];
				$labelParams['weight'] = $packageData['weight'];
				$labelParams['date'] = $packageData['date'];
				$labelParams['return_address'] = $packageData['return_address'];
				$this->generateLabel($labelParams);
			}
		}
	} else {
		return $this->response->setJsonContent(
			[
				'success' => false,
				'message' => 'failed to print label'
			]
		);
	}
}

public function getLabelsAction()
{
	if ($this->request->isPost()) {
		$data = $this->request->getJsonRawBody(true);
		if (isset($data['awbs'])) {
			foreach ($data['awbs'] as $datum) {
				if (isset($datum['tracking_number'])) {
					$awbNumbers[] = $datum['tracking_number'];
				}
			}
			sort($awbNumbers);
			$fileName = md5(implode("-", $awbNumbers)) . ".pdf";
			$path = __DIR__ . '/../../../../public/media/labels/' . $fileName;
			if (file_exists($path)) {
				$this->response->setHeader('Content-type', 'application/pdf');
				return $this->response->setFileToSend($path, $fileName, false);
			} else {
				$path = $this->mergePDFFiles($awbNumbers);
				$this->response->setHeader('Content-type', 'application/pdf');
				return $this->response->setFileToSend($path, $fileName, false);
			}
		} else {
			return $this->response->setJsonContent(
				[
					'success' => false,
					'message' => 'failed to print label'
				]
			);
		}
	} else {
		return $this->response->setJsonContent(
			[
				'success' => false,
				'message' => 'failed to print label'
			]
		);
	}
}

public function multiShipmentAction()
{
	if ($this->request->isPost()) {
		$count = 0;
		$messages = [];
		$stat = false;
		$ordersId = $this->request->getJsonRawBody();
		foreach ($ordersId->data as $oid) {

			$token = $this->tokens();
			$apiToken = $this->request->getHeader('Authorization');

			$parcelDetails = self::getOrders($token, $apiToken, (int)$oid->id);
			$parcelDetails = $parcelDetails->exportData();
			if ($parcelDetails['fulfillment_status'] == 'fulfilled') {
				continue;
			}
			if ($oid->order_data->fulfillment_status == 'fulfilled') {
				continue;
			}

			$valDat = (array)$oid;
			if (empty($valDat['order_name']) or empty($valDat['pickup_address'])) {
				return $this->response->setJsonContent(
					[
						'success' => false,
						'message' => 'Shipment Failed',
						'code' => 'Shipment Failed'
					]
				);
			}
			$pieces = [];
			$weights = [];
			$title = [];
			$category = [];
			$actualWeight = 0;
			$status = false;

			$lineItemsId = '';
			$quantity = '';
			$response = '';
			$filters = ['merchant_id' => Merchant::getMerchant(App::getShop($apiToken))->id];
			if (isset($oid->id) and !empty($oid->id)) {

				$decision = MerchantSettings::carrierShow(App::getShop($apiToken));
				$filterArray = [];
				for ($i = 0; $i < count($decision); $i++) {
					$filterArray[$decision[$i]['config_path']] = $decision[$i]['value'];
				}
				foreach (self::REQUIRED_FIELDS as $REQUIRED_FIELD) {
					if (
						$REQUIRED_FIELD == 'development_mode' or $REQUIRED_FIELD == 'include_handling_fee'
						or $REQUIRED_FIELD == 'show_frontend' or $REQUIRED_FIELD == 'shipping_method'
					) {
						if (isset($filterArray[$REQUIRED_FIELD]) and ((string)$filterArray[$REQUIRED_FIELD] == '0' or (string)$filterArray[$REQUIRED_FIELD] == '1')) {
						} else {
							return $this->response->setJsonContent(
								[
									'success' => false,
									'message' => 'Unsaved Required Settings'
								]
							);
						}
					} elseif (empty($filterArray[$REQUIRED_FIELD])) {
						return $this->response->setJsonContent(
							[
								'success' => false,
								'message' => 'Unsaved Required Settings'
							]
						);
					}
				}

				$servicable = $this->checkAvailability(json_decode($oid->order_data->shipping_address, true)['zip']);
				if ($servicable == 'Not Servicable') {
					$messages[] = 'Delivery pincode not servicable by ecom for order name ' . $oid->order_name;
					continue;
				}

				$lineItems = $oid->order_data->line_items;
				foreach ($lineItems as $lineItem1) {
					if ($lineItem1->fulfillment_status == 'fulfilled') {
						continue;
					}
					$pieces[] = $lineItem1->fulfilled_quantity;
					$weights[] = $lineItem1->grams;
					$title[] = $lineItem1->title;
					$category[] = $lineItem1->category;

					if ($lineItem1->fulfilled_quantity == 0) {
						continue;
					}
				}

				$carrierName = json_decode($oid->order_data->shipping_lines[0], true);
				foreach ($weights as $key => $wt) {
					$actualWeight += $wt * $pieces[$key];
				}

				$actualWeight = $actualWeight / 1000;
				$pieces = array_sum($pieces);


				$username = Data::getApiUserName(Merchant::getMerchant(App::getShop($apiToken))->id);
				$password = Data::getApiPassword(Merchant::getMerchant(App::getShop($apiToken))->id);

				$l = $oid->length;
				$b = $oid->breadth;
				$h = $oid->height;

				if (!is_numeric($l) or !is_numeric($b) or !is_numeric($h)) {
					return $this->response->setJsonContent(
						[
							'success' => false,
							'message' => 'Inappropriate package dimensions for order ' . $oid->order_name
						]
					);
				}

				if ($l <= 0 or $b <= 0 or $h <= 0) {

					return $this->response->setJsonContent(
						[
							'success' => false,
							'message' => 'Inappropriate package dimensions for order ' . $oid->order_name
						]
					);
				}
				if ($ordersId->package_dimension_unit == 'm') {
					$l = $l * 100;
					$b = $b * 100;
					$h = $h * 100;
				}
				$volumetricWeight = 0.2;

				if (isset($oid->volumetric_weight)) {
					$volumetricWeight = $oid->volumetric_weight * 1000;
				}
                    /*if (is_array($title)) {
                        $title = implode(' + ', $title);
                    }*/
                    if (is_array($category)) {
                    	$category = implode(' + ', $category);
                    }

                    $actualWeight = $actualWeight <= 0.1 ? 0.2 : $actualWeight;

                    $obj = new \EcomExpressAPI\API($username->value, $password->value);

                    if ($filterArray['development_mode'] == 1) {
                    	$obj->developmentMode($username->value, $password->value);
                    }

                    if ($filterArray['pickup_address_line2'] == null) {
                    	$filterArray['pickup_address_line2'] = '';
                    }

                    if ($filterArray['return_address_line2'] == null) {
                    	$filterArray['return_address_line2'] = '';
                    }

                    $product = 'PPD';
                    $collectableAmount = 0;
                    if ($oid->order_data->financial_status == 'pending') {
                    	$product = 'COD';
                    	$collectableAmount = $oid->order_data->total_price;
                    }

                    /// Ecom ==> Pincodes ///

                    $parcel_codes = EcomPincodes::findFirst([
                    	'columns' => 'state_code,dc_code',
                    	'conditions' => 'pincode =?1',
                    	'bind' => [1 => json_decode($oid->order_data->shipping_address, true)['zip']]
                    ]);

                    /// Return Address ===> Pincode

                    $parcel_return_codes = EcomPincodes::findFirst([
                    	'columns' => 'city,state',
                    	'conditions' => 'pincode =?1',
                    	'bind' => [1 => $filterArray['return_pincode']]
                    ]);


                    $custPhone = MerchantOrders::findFirst([
                    	'columns' => 'customer_phone',
                    	'conditions' => 'order_id =?1',
                    	'bind' => [1 => $oid->id]
                    ]);

                    if ($custPhone->customer_phone == null) {
                    	$custPhone->customer_phone = '0000000000';
                    }


                    $shopdetails = Merchant::findFirst([
                    	'columns' => 'shop_details',
                    	'conditions' => 'id =?1',
                    	'bind' => [1 => App::getMerchant($apiToken)->id]
                    ])->toArray();

                    $shopdetails = json_decode($shopdetails['shop_details']);
                    $shopdetails = json_decode($shopdetails, true);

                    $date = $oid->order_data->created_at->date;

                    $total_tax = 0;
                    $taxrate = 0;

                    if (isset($oid->order_data->tax_lines) and !empty($oid->order_data->tax_lines)) {
                    	$taxes = json_decode($oid->order_data->tax_lines[0], true);
                    	$taxrate = $taxes['rate'];
                    	$taxtitle = $taxes['title'];
                    	$total_tax = $oid->order_data->total_tax;
                    } else {
                    	$total_tax = $oid->order_data->total_tax;
                    	$taxrate = $oid->order_data->total_tax;
                    	$taxtitle = 'igst';
                    }

                    foreach ($oid->order_data->line_items as $lineItemKey => $lineItemValue) {
                    	if (
                    		isset($oid->order_data->line_items[$lineItemKey]->hsn_code)
                    		and empty($oid->order_data->line_items[$lineItemKey]->hsn_code)
                    	) {
                    		return $this->response->setJsonContent(
                    			[
                    				'success' => false,
                    				'message' => 'Missing HSN code or Category',
                    				'code' => self::ERROR_CODES[1]
                    			]
                    		);
                    	} elseif (!isset($oid->order_data->line_items[$lineItemKey]->hsn_code)) {
                    		return $this->response->setJsonContent(
                    			[
                    				'success' => false,
                    				'message' => 'Missing HSN Code or Cateogry


                    				'
                    			]
                    		);
                    	}
                    	if ($prodExist = MerchantProducts::findFirst(
                    		"product_id = {$oid->order_data->line_items[$lineItemKey]->product_id} AND 
                    		merchant_id = {$filters['merchant_id']}"
                    	)
                    ) {
                    		$prodExist->hsn_code = $oid->order_data->line_items[$lineItemKey]->hsn_code;
                    		$prodExist->category = $oid->order_data->line_items[$lineItemKey]->category;
                    		$prodExist->update();
                    	} else {
                    		$prodExist = new MerchantProducts();
                    		$prodExist->save([
                    			'merchant_id' => $filters['merchant_id'],
                    			'product_id' => $oid->order_data->line_items[$lineItemKey]->product_id,
                    			'category' => $oid->order_data->line_items[$lineItemKey]->category,
                    			'title' => $oid->order_data->line_items[$lineItemKey]->title,
                    			'hsn_code' => $oid->order_data->line_items[$lineItemKey]->hsn_code
                    		]);
                    	}
                    }
                    $parcel = [
                    	'AWB_NUMBER' => '',
                    	'ORDER_NUMBER' => $oid->id,
                    	'PRODUCT' => $product,
                    	'CONSIGNEE' => json_decode($oid->order_data->shipping_address, true)['name'],
                    	'CONSIGNEE_ADDRESS1' => json_decode($oid->order_data->shipping_address, true)['address1'],
                    	'CONSIGNEE_ADDRESS2' => json_decode($oid->order_data->shipping_address, true)['address2'],
                    	'CONSIGNEE_ADDRESS3' => '',
                    	'DESTINATION_CITY' => json_decode($oid->order_data->shipping_address, true)['city'],
                    	'PINCODE' => json_decode($oid->order_data->shipping_address, true)['zip'],
                    	'STATE' => json_decode($oid->order_data->shipping_address, true)['province_code'],
                    	'MOBILE' => $custPhone->customer_phone,
                    	'TELEPHONE' => $custPhone->customer_phone,
                    	'ITEM_DESCRIPTION' => implode(', ', $title),
                    	'PIECES' => $pieces,
                    	'COLLECTABLE_VALUE' => (string)$collectableAmount,
                    	'DECLARED_VALUE' => (string)$declaredAmount,
                    	'ACTUAL_WEIGHT' => $actualWeight,
                        'VOLUMETRIC_WEIGHT' => $volumetricWeight, // Optional
                        'LENGTH' => $l, // Mandatory
                        'BREADTH' => $b, // Mandatory
                        'HEIGHT' => $h, // Mandatory
                        'PICKUP_NAME' => $oid->pickup_address->pickup_name, //$filterArray['pickup_name'],
                        'PICKUP_ADDRESS_LINE1' => $oid->pickup_address->pickup_address_line1, //$filterArray['pickup_address_line1'],
                        'PICKUP_ADDRESS_LINE2' => $oid->pickup_address->pickup_address_line2, //$filterArray['pickup_address_line2'],
                        'PICKUP_PINCODE' => $oid->pickup_address->pickup_pincode, //$filterArray['pickup_pincode'],
                        'PICKUP_PHONE' => $oid->pickup_address->pickup_phone, //$filterArray['pickup_phone'],
                        'PICKUP_MOBILE' => $oid->pickup_address->pickup_phone, //$filterArray['pickup_phone'],
                        'RETURN_NAME' => $filterArray['return_name'],
                        'RETURN_ADDRESS_LINE1' => $filterArray['return_address_line1'],
                        'RETURN_ADDRESS_LINE2' => $filterArray['return_address_line2'],
                        'RETURN_PINCODE' => $filterArray['return_pincode'],
                        'RETURN_PHONE' => $filterArray['return_phone'],
                        'RETURN_MOBILE' => $filterArray['return_phone'],
                        'ADDONSERVICE' => '["NDD"]', // Optional
                        'DG_SHIPMENT' => 'false', // Mandatory
                        'ADDITIONAL_INFORMATION' => [
                        	'MULTI_SELLER_INFORMATION' => [],
                        ]
                    ];

                    $sgst = 0;
                    $cgst = 0;
                    $igst = 0;
                    $cgstRate = 0;
                    $sgstRate = 0;
                    if (
                    	$shopdetails['province_code'] ==
                    	json_decode($oid->order_data->shipping_address, true)['province_code']
                    ) {
                    	$sgst = ($taxrate) / 2;
                    	$cgst = ($taxrate) / 2;
                    	$cgstRate = ($total_tax) / 2;
                    	$sgstRate = ($total_tax) / 2;
                    	$taxrate = 0;
                    	$taxtitle = json_decode($oid->order_data->shipping_address, true)['province_code'] . ' GST';
                    } else {
                    	$igst = $total_tax;
                    }

                    foreach ($oid->order_data->line_items as $line_item_key => $line_item_value) {

                    	if (isset($line_item_value->category) and empty(trim($line_item_value->category))) {
                    		return $this->response->setJsonContent(
                    			[
                    				'success' => false,
                    				'message' => 'Missing HSN Code or Cateogry',
                    				'code' => self::ERROR_CODES[2]
                    			]
                    		);
                    	}

                    	if ($oid->order_data->line_items[$line_item_key]->fulfillment_status == 'fulfilled') {
                    		continue;
                    	}
                    	if ($hsnExist = MerchantProducts::findFirst(
                    		[
                    			'columns' => 'hsn_code',
                    			'conditions' => 'product_id =?1 AND merchant_id =?2',
                    			'bind' => [1 => $oid->order_data->line_items[$line_item_key]->product_id, 2 => $filters['merchant_id']]
                    		]
                    	)) {
                    		$parcel['ADDITIONAL_INFORMATION']['MULTI_SELLER_INFORMATION'][] = [
                                //'SELLER_TIN' => 'SELLER_TIN_1234', // Optional
                    			'INVOICE_NUMBER' => $oid->id,
                    			'INVOICE_DATE' => date('D-M-Y', strtotime($date)),
                                //'ESUGAM_NUMBER' => 'eSUGAM_1234', // Optional
                                'ITEM_CATEGORY' => $line_item_value->category, // Mandatory
                                'ITEM_DESCRIPTION' => $line_item_value->title,

                                'SELLER_NAME' => $shopdetails['name'],
                                'SELLER_ADDRESS' => $shopdetails['address1'] . ',' . $shopdetails['address2'],
                                'SELLER_STATE' => $shopdetails['province_code'],
                                'SELLER_PINCODE' => $shopdetails['zip'],
                                'SELLER_TIN' => $filterArray['seller_tin'],
                                //'PACKING_TYPE' => 'Box', // Optional
                                'PICKUP_TYPE' => $filterArray['pickup_type'],
                                'RETURN_TYPE' => $filterArray['return_type'],
                                'PICKUP_LOCATION_CODE' => '', // Optional
                                'SELLER_GSTIN' => $filterArray['seller_gstin'], // Mandatory
                                'GST_HSN' => $hsnExist->hsn_code, // Mandatory
                                'GST_ERN' => 'ERN', // Mandatory
                                'GST_TAX_NAME' => $taxtitle, // Mandatory
                                'GST_TAX_BASE' => $oid->order_data->total_price - $total_tax, // Mandatory
                                'DISCOUNT' => $oid->order_data->total_discounts,
                                'GST_TAX_RATE_CGSTN' => $cgstrate = $cgst > 0 ? $cgst : '0', // Mandatory
                                'GST_TAX_RATE_SGSTN' => $cgstrate = $sgst > 0 ? $sgst : '0', // Mandatory
                                'GST_TAX_RATE_IGSTN' => $taxrate, // Mandatory
                                'GST_TAX_TOTAL' => $total_tax,
                                'GST_TAX_CGSTN' => $cgstRate, // Mandatory
                                'GST_TAX_SGSTN' => $sgstRate, // Mandatory
                                'GST_TAX_IGSTN' => $igst // Mandatory
                            ];
                        }
                    }

                    $AWB = $obj->addParcel($parcel);
                    $num = $AWB->send($product);
                    $success = '';
                    $awbNum = '';
                    for ($i = 0; $i < count($num); $i++) {
                    	$success = ($num[$i]['success']);
                    	$awbNum = $num[$i]['awb'];
                    }

                    if ($success == 1) {

                    	if (isset($lineItems) and !empty($lineItems)) {

                    		foreach ($lineItems as $item) {
                    			$lineItemsId = $item->id;
                    			$quantity = $item->fulfilled_quantity;
                    			if ($item->fulfillment_status == 'fulfilled') {
                    				continue;
                    			}
                    			if ($quantity != 0) {
                    				$ship = new MerchantShipment();
                    				$count = 1;
                    				if ($count == 1) {
                    					sleep(20);
                    				}
                    				$status = $ship->ship($token, App::getShop($apiToken), $oid->id, $awbNum, $lineItemsId, 'https://ecomexpress.in/tracking/?awb_field=' . $awbNum . '&s=', $quantity);
                    			} elseif ($quantity == 0) {
                    				return $this->response->setJsonContent(
                    					[
                    						'success' => false,
                    						'message' => 'Neglected Fulfilled items'
                    					]
                    				);
                    			}
                    			if ($status == false) {
                    				return $this->response->setJsonContent(
                    					[
                    						'success' => false,
                    						'message' => 'Please try again if unfulfilled'
                    					]
                    				);
                    			}
                    		}
                    	} else {
                    		return json_encode(
                    			[
                    				'success' => false,
                    				'message' => 'No items to fulfill'
                    			]
                    		);
                    	}
                    	$token = $this->tokens();
                    	$client = new Api([
                    		'api_key' => \App\Ecom\Models\App::APP_API_KEY,
                    		'api_secret' => \App\Ecom\Models\App::APP_SECRET_KEY,
                    		'myshopify_domain' => App::getShop($apiToken),
                    		'access_token' => $token
                    	]);
                    	$helper = new OrderFulfillmentService($client);
                    	$oId = $oid->id;
                    	$id = $helper->all($oId)[0]->id;

                    	if (isset($awbNum) and isset($id)) {
                    		$trackingNums = [];
                    		$trackingNumber = MerchantOrders::findFirst("order_id = '{$oid->id}' ");
                    		if (isset($trackingNumber->tracking_number)) {
                    			$trackingNums = explode(',', $trackingNumber->tracking_number);
                    		}
                    		$trackingNums[] = $awbNum;
                    		$trackingNumber->tracking_number = implode(',', $trackingNums);
                    		$trackingNumber->ecom_status = 'Ready To Be Shipped';
                    		$trackingNumber->update();
                    		$this->getDI()->get('log')->logContent('Updated_MSHIP', 7, 'webhook.log');
                    		$parcelDetails1 = self::getOrders($token, $apiToken, $oid->id);

                    		$parcelDetails1 = json_encode($parcelDetails1, true);
                    		$details1 = json_decode($parcelDetails1, true);
                    		$det1 = json_decode($details1, true);

                    		$lineItems1 = $det1['line_items'];

                    		$shipData = json_encode(array_values($lineItems1), true);

                    		$shipment = new MerchantShipment();
                    		$shipment->save([
                    			'merchant_id' => $filters['merchant_id'],
                    			'order_id' => $oid->id,
                    			'order_name' => $oid->order_name,
                    			'tracking_number' => $awbNum,
                    			'tracking_url' => 'https://ecomexpress.in/tracking/?awb_field=' . $awbNum . '&s=',
                    			'shipment_status' => 'Fulfilled',
                    			'shipment_data' => $shipData,
                    			'fulfillment_id' => $id,
                    			'created_at' => date("Y-m-d"),
                    			'package_dimensions' => '{"length":' . $l . ',"breadth":' . $b . ',"height":' . $h . '}',
                    			'shipping_carrier' => $carrierName['title'],
                    			'ecom_status' => 'Shipment In process',
                    			'order_fulfilled_at' => date("Y-m-d")
                    		]);

                    		$date = $det1['created_at']['date'];
                    		$stat = true;
                    		$labelParams['name'] = json_decode($det1['shipping_address'], true)['name'];
                    		$labelParams['awb_number'] = $awbNum;
                    		$labelParams['order_number'] = $oid->id;
                    		$labelParams['payment'] = $product;
                    		$labelParams['state_code'] = $parcel_codes['state_code'];
                    		$labelParams['dc_code'] = $parcel_codes['dc_code'];
                    		$labelParams['phone'] = $custPhone->customer_phone;
                    		$labelParams['customer_add1'] = json_decode($det1['shipping_address'], true)['address1'];
                    		$labelParams['customer_add2'] = json_decode($det1['shipping_address'], true)['address2'];
                    		$labelParams['pincode'] = json_decode($det1['shipping_address'], true)['zip'];
                    		$labelParams['item_description'] = implode(', ', $title);
                    		$labelParams['quantity'] = $pieces;
                    		$labelParams['length'] = $l;
                    		$labelParams['breadth'] = $b;
                    		$labelParams['height'] = $h;
                    		$labelParams['value'] = $oid->order_data->total_price;
                    		$labelParams['weight'] = $actualWeight;
                    		$labelParams['date'] = date("d-M-Y H:i", strtotime($date));
                    		$labelParams['return_address'] =
                    		$filterArray['return_address_line1']
                    		. ' ' . $filterArray['return_address_line2']
                    		. ' ' . $parcel_return_codes['city']
                    		. ' ' . $parcel_return_codes['state']
                    		. ' ' . $filterArray['return_pincode'];

                    		$this->generateLabel($labelParams);
                    	}
                    } elseif ($success == 0) {
                    	for ($i = 0; $i < count($num); $i++) {
                    		$responseReason = ($num[$i]['reason']);
                    		$orderNumber = ($num[$i]['order_number']);
                    		$orderName = MerchantOrders::findFirst("order_id = {$orderNumber}")->toArray();
                    		$messages[] = $responseReason . ' For Order Name. ' . $orderName['order_name'];
                    	}
                    	continue;
                    }
                }
            }

            if ($stat == true) {
            	if (isset($messages) and !empty($messages)) {
            		return $this->response->setJsonContent(
            			[
            				'success' => false,
            				'message' => $messages,
            				'display' => true
            			]
            		);
            	} else {
            		return $this->response->setJsonContent(
            			[
            				'success' => true,
            				'message' => 'Fulfillment Successful',
            			]
            		);
            	}
            } else {
            	return $this->response->setJsonContent(
            		[
            			'success' => false,
            			'message' => $messages,
            			'display' => true
            		]
            	);
            }
        }
        return '{}';
    }

    public function updateProductAction()
    {
    	if ($this->request->isPost()) {
    		$data = $this->request->getJsonRawBody(true);
    		foreach (self::PRODUCT_UPDATION_FIELDS as $UPDATION_FIELD) {
    			if (!isset($data[$UPDATION_FIELD]) or empty($data[$UPDATION_FIELD])) {
    				return $this->response->setJsonContent(
    					[
    						'success' => false,
    						'message' => 'Failed To Save Product',
    						'display' => true
    					]
    				);
    			}
    		}
    		if (isset($data['hsn_code']) and !empty($data['hsn_code'])) {
    			if (!is_numeric($data['hsn_code'])) {
    				return $this->response->setJsonContent(
    					[
    						'success' => false,
    						'message' => 'Hsn must be numeric'
    					]
    				);
    			}
    		}
    		if (isset($data['length'], $data['breadth'], $data['height']) and !empty($data['length']) and !empty($data['breadth']) and !empty($data['height'])) {
    			if (!(float)($data['length']) or (float)($data['length']) < 0) {
    				return $this->response->setJsonContent(
    					[
    						'success' => false,
    						'message' => 'L,B,H must be numeric'
    					]
    				);
    			} elseif (!(float)($data['breadth']) or (float)($data['breadth']) < 0) {
    				return $this->response->setJsonContent(
    					[
    						'success' => false,
    						'message' => 'L,B,H must be numeric'
    					]
    				);
    			} elseif (!(float)($data['height']) or (float)($data['height']) < 0) {
    				return $this->response->setJsonContent(
    					[
    						'success' => false,
    						'message' => 'L,B,H must be numeric'
    					]
    				);
    			}
    		} else {
    			return $this->response->setJsonContent(
    				[
    					'success' => false,
    					'message' => 'Invalid package dimensions'
    				]
    			);
    		}
    		if (isset($data) and !empty($data)) {
    			$locationName = MerchantLocations::findFirst([
    				'columns' => 'location_name',
    				'conditions' => 'location_id =?1',
    				'bind' => [1 => $data['location_id']]
    			]);
    			if ($products = MerchantProducts::findFirst("merchant_id = {$data['merchant_id']} 
    				AND product_id = {$data['product_id']} AND variant_id = {$data['variant_id']}")) {
    				$products->length = $data['length'];
    				$products->breadth = $data['breadth'];
    				$products->height = $data['height'];
    				$products->category = $data['category'];
    				$products->hsn_code = $data['hsn_code'];
    				$products->location_id = $data['location_id'];
    				$products->location_name = $locationName->location_name;
    				$products->update();
    				return $this->response->setJsonContent(['success' => true, 'message' => 'Updated Successfully']);
    			} else {
    				return $this->response->setJsonContent(['success' => false, 'message' => 'No Data Found']);
    			}
    		}
    	}
    	return $this->response->setJsonContent(['success' => false, 'message' => 'Invalid request']);
    }

    public function uploadAddressesAction()
    {
    	if ($this->request->isPost()) {
    		if ($this->request->hasFiles() == true) {
    			$stats = false;
    			$name = '';
    			$dataArray = [];
    			$apiToken = $this->request->getHeader('Authorization');
    			$merchant = Merchant::findFirst([
    				"columns" => "id",
    				"conditions" => "shop_url = ?1",
    				"bind" => [1 => App::getShop($apiToken)]
    			]);
    			foreach ($this->request->getUploadedFiles() as $file) {
    				if ($file->getExtension() != 'csv') {
    					return $this->response->setJsonContent(
    						[
    							'success' => false,
    							'message' => 'Please Select CSV file only'
    						]
    					);
    				}
    				$name = $file->getName();
    				$file->moveTo(__DIR__ . '/../../../../public/media/' . $file->getName());
    			}
    			$csv = array_map('str_getcsv', file(__DIR__ . '/../../../../public/media/' . $name));

    			if ($csv[0] == [
    				'pickup_name',
    				'pickup_type',
    				'pickup_address_line1',
    				'pickup_address_line2',
    				'pickup_pincode',
    				'pickup_phone'
    			]) {
    				unset($csv[0]);
    			} else {
    				return $this->response->setJsonContent(
    					[
    						'success' => false,
    						'message' => 'Invalid Format Please Upload as provided Sample Format',
    						'code' => self::ERROR_CODES[3]
    					]
    				);
    			}
    			foreach ($csv as $value) {
    				$dataArray = $csv;
    			}
    			foreach ($dataArray as $key => $item) {

    				if (
    					empty(trim($dataArray[$key][0]))
    					and empty(trim($dataArray[$key][1]))
    					and empty(trim($dataArray[$key][2]))
    				) {
    					return $this->response->setJsonContent(
    						[
    							'success' => false,
    							'message' => 'Missing Pickup Information'
    						]
    					);
    				}

    				if (is_numeric($dataArray[$key][4]) == false) {
    					return $this->response->setJsonContent(
    						[
    							'success' => false,
    							'message' => 'Invalid Pincode'
    						]
    					);
    				}
    				if (is_numeric($dataArray[$key][5]) == false) {
    					return $this->response->setJsonContent(
    						[
    							'success' => false,
    							'message' => 'Invalid Phone Number '
    						]
    					);
    				}
    				$ARR = ['WH', 'RH', 'SL'];

    				if (!in_array(trim($dataArray[$key][1]), $ARR)) {

    					return $this->response->setJsonContent(
    						[
    							'success' => false,
    							'message' => 'Invalid Pickup Type'
    						]
    					);
    				}

    				if (strlen((int)$dataArray[$key][5]) != 10) {
    					return $this->response->setJsonContent(
    						[
    							'success' => false,
    							'message' => 'Phone Number Must Be 10 Digits Only'
    						]
    					);
    				}

    				$stats = true;
    			}
    			if ($stats == true) {

    				foreach ($dataArray as $key => $item) {

    					if ($alreadyPresent = PickupAddresses::findFirst(
    						[
    							'conditions' => 'merchant_id =?1 AND pickup_name =?2',
    							'bind' => [1 => $merchant->id, 2 => $dataArray[$key][0]]
    						]
    					)) {
    						$alreadyPresent->pickup_address_line1 = $dataArray[$key][2];
    						$alreadyPresent->pickup_address_line2 = $dataArray[$key][3];
    						$alreadyPresent->pickup_pincode = (int)$dataArray[$key][4];
    						$alreadyPresent->pickup_phone = (int)$dataArray[$key][5];
    						$alreadyPresent->pickup_type = $dataArray[$key][1];
    						$alreadyPresent->update();
    						$save = true;
    					} else {
    						$pickupAddresses = new PickupAddresses();
    						$save = $pickupAddresses->save([
    							'merchant_id' => $merchant->id,
    							'pickup_name' => $dataArray[$key][0],
    							'pickup_address_line1' => $dataArray[$key][2],
    							'pickup_address_line2' => $dataArray[$key][3],
    							'pickup_pincode' => (int)$dataArray[$key][4],
    							'pickup_phone' => (int)$dataArray[$key][5],
    							'pickup_type' => $dataArray[$key][1]
    						]);
    					}

    					if ($save == false) {
    						return $this->response->setJsonContent(
    							[
    								'success' => false,
    								'message' => 'Missing Address Fields'
    							]
    						);
    					}
    				}
    			}
    			return $this->response->setJsonContent([
    				'success' => true,
    				'message' => 'Addresses Uploaded'
    			]);
    		}
    	}
    	return $this->response->setJsonContent(['success' => false, 'message' => 'Invalid Request']);
    }

    protected function mergePDFFiles(array $awbNumbers)
    {
    	try {
    		if ($awbNumbers) {
    			$filesTotal = sizeof($awbNumbers);
    			$fileNumber = 1;

    			$mpdf = new Mpdf();
    			$mpdf->SetImportUse();

    			$fileName = md5(implode("-", $awbNumbers)) . ".pdf";
    			$path = __DIR__ . '/../../../../public/media/labels/';
    			$filePath = __DIR__ . '/../../../../public/media/labels/' . $fileName;
    			$handle = fopen($path, 'w');
    			fclose($handle);

    			foreach ($awbNumbers as $awb) {
    				if (file_exists($path . $awb . ".pdf")) {
    					$pagesInFile = $mpdf->SetSourceFile($path . $awb . ".pdf");
    					for ($i = 1; $i <= $pagesInFile; $i++) {
    						$tplId = $mpdf->ImportPage($i);
    						$mpdf->UseTemplate($tplId);
    						$mpdf->WriteHTML('<pagebreak />');
    					}
    				}
    			}
    			$fileNumber++;
    			$mpdf->Output($filePath, 'F');
    			return $filePath;
    		}
    	} catch (\Exception $exception) {
    		$this->getDI()
    		->get('log')
    		->logContent(__METHOD__ . ' ' . var_export($exception->getMessage(), true), 7, 'exception.log');
    	}
    }

    public function syncRefundedOrderAction()
    {


    	$response = [
    		'success' => false,
    		'message' => 'Failed to sync order from shopify'
    	];
    	if ($this->request->isPost()) {
    		$messages = [];
    		$responseReason="";
    		$taxtitle = '';
    		$collectableAmount = 0;
    		$totalTax = 0;
    		$data=[];
    		$awbNumber='';
    		$id = $this->request->getQuery('id');
    		$id = json_decode($id, true);
    		$token = $this->tokens();
    		$apiToken = $this->request->getHeader('Authorization');
    		$fdata = $this->request->getJsonRawBody(true);
    		$extraData=$fdata['extradata'];
    		$address=$fdata['address'];
    		$merchant = Merchant::findFirst([
    			"columns" => "id,token",
    			"conditions" => "shop_url = ?1",
    			"bind" => [1 => App::getShop($apiToken)]
    		]);
    		$client = new Api([
    			'api_key' => \App\Ecom\Models\App::APP_API_KEY,
    			'api_secret' => \App\Ecom\Models\App::APP_SECRET_KEY,
    			'myshopify_domain' => App::getShop($apiToken),
    			'access_token' => $merchant->token
    		]);

    		$helper = new RefundService($client);
    		$order_id = MerchantOrders::findFirst([
    			'columns' => 'order_id,order_refund_id',
    			'conditions' => 'id =?1',
    			'bind' => [1 => $id]
    		]);
    		$orderId=(int)$order_id->order_id;
    		$orders = new OrderService($client);
    		$ordersData = $orders->get($orderId);
    		$ordersData1=json_encode($ordersData);
    		$ordersData2=json_decode($ordersData1,true);
    		$ordersData2=json_decode($ordersData2,true);
    		// print_r($ordersData2['phone']);
    		// die("test is here ");
    		$revpickup_mob=0;
    		if(isset($ordersData2['phone']))
    		{
    			$revpickup_mob=$ordersData2['phone'];
    		}
    	
    		$orderRefundId=(int)$order_id->order_refund_id;

    		$refundData = $helper->get($orderId,$orderRefundId);

    		$refundData = json_encode($refundData);
    		$newvariable = json_decode($refundData, true);
    		$newvariable = json_decode($newvariable, true);
    		$date=$newvariable["created_at"] ["date"];
    		$date=explode(" ", $date);
    		$date = date("d-M-Y", strtotime($date[0]));
    		$shopdetails = Merchant::findFirst([
    			'columns' => 'shop_details',
    			'conditions' => 'id =?1',
    			'bind' => [1 => App::getMerchant($apiToken)->id]
    		])->toArray();

    		$shopdetails = json_decode($shopdetails['shop_details']);
    		$shopdetails = json_decode($shopdetails, true); 
    		$username = Data::getApiUserName(Merchant::getMerchant(App::getShop($apiToken))->id);
    		$password = Data::getApiPassword(Merchant::getMerchant(App::getShop($apiToken))->id);
    		$decision = MerchantSettings::carrierShow(App::getShop($apiToken));
    		$filterArray = [];
    		for ($i = 0; $i < count($decision); $i++) {
    			$filterArray[$decision[$i]['config_path']] = $decision[$i]['value'];
    		}


    		$obj = new \EcomExpressAPI\API($username->value, $password->value);
    		if ($filterArray['development_mode'] == 1) {
    			$obj->developmentMode($username->value, $password->value);
    		}


    		if ($filterArray['pickup_address_line2'] == null) {
    			$filterArray['pickup_address_line2'] = '';
    		}

    		if ($filterArray['return_address_line2'] == null) {
    			$filterArray['return_address_line2'] = '';
    		}
    		
    		if(!isset($fdata['data']) && empty($fdata['data']))
    		{
    			return json_encode([
    				'success' => false,
    				'message' => 'Please select any product',

    			]);
    		}
    		$data=$fdata['data'];

    		$revpickup_name=$address['name'];
    		$revpickup_address=$address['address1'];
    		$revpickup_city=$address['city'];
    		$revpickup_pincode=$address['zip'];
    		$revpickup_state=$address['province_code'];
    		// $revpickup_mob=$address['phone'];
    		$revpickup_telephone=$address['phone'];
    		$drop_name=$address['pickup_name'];
    		$drop_address_line1=$address['pickup_address_line1'];
    		$drop_pincode=$address['pickup_pincode'];
    		$drop_mobile=$address['pickup_phone'];
    		$drop_phone=$address['pickup_mobile'];
    		$dg_shipment=false;
    		$order_id=$extraData['order_id'];

    		$product='REV';
    		$invoice=$extraData['order_id'];
    		$pieces=$extraData['totalQuantity'];
    		$actual_weight=$extraData['totalWeight'];
    		
    		$length=$extraData['totalLength'];
    		$breadth=$extraData['totalBreadth'];
    		$height=$extraData['totalHeight'];

    		$volumetric_weight=($length*$breadth*$height)/5000;

    		$parcelDetails = self::getOrders($token, $apiToken, $order_id);
    		$parcelDetails = json_encode($parcelDetails, true);
    		$details = json_decode($parcelDetails, true);
    		$det = json_decode($details, true);
    		$taxtitle = json_decode($det['shipping_address'], true)['province_code'] . ' GST'; 

    		foreach ($data as $key => $value) {
    		$collectableAmount+=($value['price']* $value['quantity'])+($value['price']* $value['quantity'])*($value['tax_lines'][0]['rate']);      
    		$totalTax += ($value['price'] * $value['quantity']) * ($value['tax_lines'][0]['rate']);
    		}
    			$item_description="";
    			$collectableValue=$collectableAmount;
    		    $sgst = ($totalTax) / 2;
    			$cgst = ($totalTax) / 2;
    			$sgstRate = 0.9;
    			$cgstRate = 0.9;
    			$igstrate = 0;
    			$igst = $totalTax;   

    			$getAwb = MerchantShipment::findFirst([
    				'columns' => 'tracking_number',
    				'conditions' => 'order_id =?1',
    				'bind' => [1 =>418715107385]
    				//remove bind with order id 418715107385 or $order_id
    			]);
    			if(!$getAwb){
    				return json_encode([
    					'success' => false,
    					'message' => 'This product is not shipped ',

    				]);
    			}
    			else{
    				$getAwb=(int)$getAwb['tracking_number'];
    				$awbNumber=$getAwb;	
    			}
    			$map=[
    				"ECOMEXPRESS-OBJECTS"=>[
    					'SHIPMENT'=>[
    						'AWB_NUMBER'=>(string)$awbNumber,
    						"ORDER_NUMBER"=> (string)$order_id,
    						'PRODUCT'=>'REV',
    						'REVPICKUP_NAME'=>(string)$revpickup_name,
    						"REVPICKUP_ADDRESS1"=>(string)$revpickup_address,
    						"REVPICKUP_ADDRESS2"=> "Change Address 2",
            				"REVPICKUP_ADDRESS3"=> "Change Address 3",
    						"REVPICKUP_CITY"=>(string)$revpickup_city,
    						"REVPICKUP_PINCODE"=>(string)$revpickup_pincode,
    						"REVPICKUP_STATE"=>(string)$revpickup_state,
    						"REVPICKUP_MOBILE"=>(string)$revpickup_mob,
    						"REVPICKUP_TELEPHONE"=>(string)$revpickup_telephone,
    						'ITEM_DESCRIPTION'=>(string)$item_description,
    						'PIECES'=>(int)$pieces,
    						'COLLECTABLE_VALUE'=>(double)$collectableValue,
    						"DECLARED_VALUE"=>(double)$collectableValue,
    						'ACTUAL_WEIGHT'=>(double)$actual_weight,
    						'VOLUMETRIC_WEIGHT'=>10.58,//$volumetric_weight,
    						'LENGTH'=>(double)$length,
    						'BREADTH'=>(double)$breadth,
    						'HEIGHT'=>(double)$height,
    						"VENDOR_ID"=> "",
    						'DROP_NAME'=>(string)$drop_name,
    						'DROP_ADDRESS_LINE1'=>(string)$drop_address_line1,
    						"DROP_ADDRESS_LINE2"=> "Drop Change Address 2",
    						'DROP_PINCODE'=>(string)$drop_pincode,
    						'DROP_MOBILE'=>(string)$drop_mobile,
    						 "ITEM_DESCRIPTION"=> "XYZ",
    						'DROP_PHONE'=>(string)$drop_phone,
    						"EXTRA_INFORMATION"=> "test info",
    						'DG_SHIPMENT'=>"false",
    						'ADDITIONAL_INFORMATION'=>[
    							 "SELLER_TIN"=> null,
    							'INVOICE_NUMBER' => (string)$invoice,
    							"INVOICE_DATE"=>$date,
    							"ESUGAM_NUMBER"=> null,
                				"ITEM_CATEGORY"=> null,
                				"PACKING_TYPE"=> null,
                				"PICKUP_TYPE"=> null,
                				"RETURN_TYPE"=> null,
                				"PICKUP_LOCATION_CODE"=> "WH",
    							'SELLER_GSTIN' => (string)$filterArray['seller_gstin'],
    							"GST_HSN"=> null,
                				"GST_ERN"=> null,
    							'GST_TAX_NAME' => (string)$taxtitle,
    							'GST_TAX_BASE' => (double)$collectableAmount - $totalTax,
    							'GST_TAX_RATE_CGSTN' => (float)$cgstrate = $cgst > 0 ? $sgstRate : '0',
    							'GST_TAX_RATE_SGSTN' => (float)$cgstrate = $cgst > 0 ? $cgstRate : '0',
    							'GST_TAX_RATE_IGSTN' => (float)$igstrate,
    							'GST_TAX_TOTAL' => (double)$totalTax,
    							'GST_TAX_CGSTN'=>(double)$sgst,
    							'GST_TAX_SGSTN' => (double)$cgst,
    							'GST_TAX_IGSTN' => (double)$igst,
    							"DISCOUNT"=> null
    						]

    					]
    				]
    			];
    			// print_r(json_encode($map));
    			// print_r($obj);
    			// die("here");
    			$AWB = $obj->addParcel($map);
    			$num = $AWB->send($product);
    			$success = '';
                $awbNum = '';
				if(isset($num['AIRWAYBILL-OBJECTS'],$num['AIRWAYBILL-OBJECTS']['AIRWAYBILL'],$num['AIRWAYBILL-OBJECTS']['AIRWAYBILL']['success']))
                {
                	$success=$num['AIRWAYBILL-OBJECTS']['AIRWAYBILL']['success'];
                	
                }else{
                	die("ghadgs");
                }
                // var_dump($success);
    			if ($success==1 || $success== "True") {
    				$orderNumber=$num['AIRWAYBILL-OBJECTS']['AIRWAYBILL']['order_id'];
    				$awbNumber=$num['AIRWAYBILL-OBJECTS']['AIRWAYBILL']['airwaybill_number'];
    				print_r($awbNumber);
    				print_r("got it");
    			}else{
    				$responseReason=$num['AIRWAYBILL-OBJECTS']['AIRWAYBILL']['error_list']['reason_comment'];
    				$orderNumber=$num['AIRWAYBILL-OBJECTS']['AIRWAYBILL']['order_id'];
    				$orderName = MerchantOrders::findFirst("order_id = {$orderNumber}")->toArray();
    				$messages[] = $responseReason . ' For Order Name. ' . $orderName['order_name'];
    				print_r($messages);
    			}
    		die(" dd");
    	}
    	die("O HELLO");
    }

    public function syncProductsAction()
    {
    	$response = [
    		'success' => false,
    		'message' => 'Failed to sync products from shopify'
    	];
    	if ($this->request->isGet()) {
    		$apiToken = $this->request->getHeader('Authorization');
    		$merchant = Merchant::findFirst([
    			"columns" => "id,token",
    			"conditions" => "shop_url = ?1",
    			"bind" => [1 => App::getShop($apiToken)]
    		]);
    		$client = new Api([
    			'api_key' => \App\Ecom\Models\App::APP_API_KEY,
    			'api_secret' => \App\Ecom\Models\App::APP_SECRET_KEY,
    			'myshopify_domain' => App::getShop($apiToken),
    			'access_token' => $merchant->token
    		]);
    		$helper = new ProductService($client);
    		$totalProducts = $helper->count();
    		$message = 'Count Found';
    		if ($totalProducts == 0) {
    			$message = 'No Products Found';
    		}
    		$response = [
    			'success' => true,
    			'message' => $message,
    			'data' => ['count' => $totalProducts, 'total_pages' => ceil(($totalProducts / 250))]
    		];
    	}
    	return $this->response->setJsonContent($response);
    }

    public function getSyncedProductsAction()
    {
    	try {

    		$response = [
    			'success' => false,
    			'message' => 'Syncing Failed'
    		];
    		if ($this->request->isPost()) {
    			$page = $this->request->getJsonRawBody(true);
    			$apiToken = $this->request->getHeader('Authorization');
    			$domain = App::getShop($apiToken);
    			$merchant = Merchant::findFirst([
    				"columns" => "id,token",
    				"conditions" => "shop_url = ?1",
    				"bind" => [1 => $domain]
    			]);
    			$client = new Api([
    				'api_key' => \App\Ecom\Models\App::APP_API_KEY,
    				'api_secret' => \App\Ecom\Models\App::APP_SECRET_KEY,
    				'myshopify_domain' => App::getShop($apiToken),
    				'access_token' => $merchant->token
    			]);

    			$helper = new ProductService($client);

    			$products = $helper->all(
    				[
    					'limit' => 250,
    					'page' => $page['page'],
    					'fields' => 'title,id,handle,product_type,handle,variants'
    				]
    			);
    			foreach ($products as $product) {
    				$productTitle = $product->getData('title');
    				$productCategory = $product->getData('product_type');
    				$productHandle = $product->getData('handle');
    				$variantProduct = $product->getData('variants');
    				foreach ($variantProduct as $variantItems) {
    					if ($variantItems->title != 'Default Title') {
    						$title = $productTitle . ' - ' . $variantItems->title;
    					} else {
    						$title = $productTitle;
    					}

    					if ($productExist = MerchantProducts::findFirst(
    						"product_id = {$product->getData('id')} 
    						AND merchant_id = {$merchant->id} AND variant_id = {$variantItems->id}"
    					)) {

    						$productExist->title = $title;
    						$productExist->category = $productCategory;
    						$productExist->handle = $productHandle;
    						$productExist->update();
    						$response = [
    							'success' => true,
    							'message' => 'Syncing Succeded',
    							'data' => ['page' => $page['page']]
    						];
    					} else {
    						$productExist = new MerchantProducts();
    						$productExist->save(
    							[
    								'merchant_id' => $merchant->id,
    								'product_id' => $product->getData('id'),
    								'variant_id' => $variantItems->id,
    								'title' => $title,
    								'category' => $productCategory,
    								'handle' => $productHandle
    							]
    						);
    						$response = [
    							'success' => true,
    							'message' => 'Syncing Succeded',
    							'data' => ['page' => $page['page']]
    						];
    					}
    				}
    			}
    		}
    	} catch (\Exception $exception) {
    		$this->getDI()->get('log')
    		->logContent(__METHOD__ . ' ' . $exception->getMessage(), 7, "exception-{$domain}.log");
    	}
    	return $this->response->setJsonContent($response);
    }

    public function cancelledproductAction()
    {
    	$response = [
    		'success' => false,
    		'message' => 'No Data Found',
    		'data' => []
    	];

    	if ($this->request->isget()) {
    		$apiToken = $this->request->getHeader('Authorization');
    		$merchant = Merchant::findFirst([
    			"columns" => "id,token",
    			"conditions" => "shop_url = ?1",
    			"bind" => [1 => App::getShop($apiToken)]
    		]);

    		$mid = $merchant->id;

    		$filters = ['merchant_id' => $mid, 'order_status' => 'cancelled'];
    		$filtersEncoded = $this->request->getQuery('filters');
    		$offset = $this->request->getQuery('page');
    		if (isset($filtersEncoded) and !empty($filtersEncoded)) {
    			$filtersArray = json_decode(base64_decode($filtersEncoded), true);
    			if (is_array($filtersArray)) {
    				$filters = array_merge($filters, $filtersArray);
    			}
    		}
    		$orders = new MerchantOrders();
    		if (count($orders->getCancelledOrders($filters, $offset)) != 0) {

    			$ordersData = $orders->getOrders($filters, $offset);
    			$response =
    			[
    				'success' => true,
    				'message' => 'Success',
    				'data' => $ordersData['orders'],
    				'count' => $ordersData['total_counts']
    			];
    		}
    		return $this->response->setJsonContent($response);
    	}
    	return $this->response->setJsonContent($response);
    }

    public function refundproductAction()
    {
    	$response = [
    		'success' => false,
    		'message' => 'No Data Found',
    		'data' => []
    	];

    	if ($this->request->isget()) {
    		$apiToken = $this->request->getHeader('Authorization');
    		$merchant = Merchant::findFirst([
    			"columns" => "id,token",
    			"conditions" => "shop_url = ?1",
    			"bind" => [1 => App::getShop($apiToken)]
    		]);

    		$mid = $merchant->id;

    		$filters = ['merchant_id' => $mid, 'order_status' => 'refunded'];
    		$filtersEncoded = $this->request->getQuery('filters');
    		$offset = $this->request->getQuery('page');
    		if (isset($filtersEncoded) and !empty($filtersEncoded)) {
    			$filtersArray = json_decode(base64_decode($filtersEncoded), true);
    			if (is_array($filtersArray)) {
    				$filters = array_merge($filters, $filtersArray);
    			}
    		}
    		$orders = new MerchantOrders();
    		if (count($orders->getRefundedOrders($filters, $offset)) != 0) {

    			$ordersData = $orders->getOrders($filters, $offset);
    			
    			$response =
    			[
    				'success' => true,
    				'message' => 'Success',
    				'data' => $ordersData['orders'],
    				'count' => $ordersData['total_counts']
    			];
    		}
    		return $this->response->setJsonContent($response);
    	}
    	return $this->response->setJsonContent($response);
    }

    public function generateManifestAction()
    {
    	if ($this->request->isGet()) {
    		$manifestDetails = [];
    		$apiToken = $this->request->getHeader('Authorization');
    		$merchant = Merchant::findFirst([
    			"columns" => "id,token",
    			"conditions" => "shop_url = ?1",
    			"bind" => [1 => App::getShop($apiToken)]
    		]);
    		$mid = $merchant->id;
    		$dateWiseManifest = $this->request->getQuery('range');
    		$dateWiseManifest = json_decode($dateWiseManifest, true);
    		$decision = MerchantSettings::carrierShow(App::getShop($apiToken));
    		$filterArray = [];
    		for ($i = 0; $i < count($decision); $i++) {
    			$filterArray[$decision[$i]['config_path']] = $decision[$i]['value'];
    		}

    		preg_match_all('!\d+!', $filterArray['user_name'], $matches);
    		$manifestNumber = (end($matches[0]));
    		$manifestNumber .= 2018;
    		$allids = MerchantManifest::find(
    			[
    				'columns' => 'id',
    				'conditions' => 'merchant_id =?1',
    				'bind' => [1 => $mid]
    			]
    		)->toArray();
    		if (isset($allids) and !empty($allids)) {
    			$manifestNumber .= (end($allids)['id']) + 1;
    		} else {
    			$manifestNumber .= 1;
    		}

    		$shopdetails = Merchant::findFirst([
    			'columns' => 'shop_details',
    			'conditions' => 'id =?1',
    			'bind' => [1 => App::getMerchant($apiToken)->id]
    		])->toArray();

    		$shopdetails = json_decode($shopdetails['shop_details']);
    		$shopdetails = json_decode($shopdetails, true);

    		date_default_timezone_set('Asia/Kolkata');
    		$date = date('d-m-Y H:i');

    		$manifestDetails['manifest_number'] = $manifestNumber;
    		$manifestDetails['print_date_time'] = $date;
    		$manifestDetails['shipper_acount_code'] = (end($matches[0]));
    		$manifestDetails['shipper_name'] = $shopdetails['name'];
    		$manifestDetails['pickup_name'] = $filterArray['pickup_name'];
    		$manifestDetails['pickup_type'] = $filterArray['pickup_type'];
    		$manifestDetails['pickup_address'] =
    		$filterArray['pickup_address_line1'] . ' ' . $filterArray['pickup_address_line2'];
    		$manifestDetails['pickup_pincode'] = $filterArray['pickup_pincode'];
    		$manifestDetails['contact_number'] = $filterArray['pickup_phone'];

    		$shippingDetails = MerchantShipment::find(
    			[
    				'columns' => 'tracking_number',
    				'conditions' => 'merchant_id =?1 AND created_at BETWEEN ?2 AND ?3',
    				'bind' => [1 => $mid, 2 => trim($dateWiseManifest['from']), 3 => trim($dateWiseManifest['to'])]
    			]
    		);

    		if (isset($shippingDetails)) {
    			$shippingDetails = $shippingDetails->toArray();
    		}
    		$obj = new \EcomExpressAPI\API($filterArray['user_name'], $filterArray['api_password']);

    		if ($filterArray['development_mode'] == 1) {
    			$obj->developmentMode($filterArray['user_name'], $filterArray['api_password']);
    		}

    		foreach ($shippingDetails as $shippingDetail) {
    			try {
    				$latestDetails = $obj->track($shippingDetail['tracking_number']);
    				$ecomStatus = MerchantShipment::findFirst("tracking_number = {$shippingDetail['tracking_number']}");
    				$ecomStatus->ecom_status = $latestDetails[$shippingDetail['tracking_number']]['status'];
    				$ecomStatus->ecom_status_code = $latestDetails[$shippingDetail['tracking_number']]['reason_code_number'];
    				$ecomStatus->update();
    			} catch (\Exception $exception) {
    				$this->getDI()
    				->get('log')
    				->logContent(var_export($exception->getMessage()), 7, 'exception.log');
    			}
    		}

    		$manifestData = MerchantShipment::find(
    			[
    				'columns' => 'order_id,label_data',
    				'conditions' => 'ecom_status_code =?1 AND merchant_id =?2 AND created_at BETWEEN ?3 AND ?4',
    				'bind' => [1 => '001', 2 => $mid, 3 => trim($dateWiseManifest['from']), 4 => trim($dateWiseManifest['to'])]
    			]
    		)->toArray();
    		if (isset($manifestData) and !empty($manifestData)) {
    			$manifestDetails['manifest_data'] = [];
    			foreach ($manifestData as $manifestDatum) {
    				$labelDataArray = unserialize($manifestDatum['label_data']);
    				$city = EcomPincodes::findFirst(
    					[
    						'columns' => 'city',
    						'conditions' => 'pincode =?1',
    						'bind' => [1 => $labelDataArray['pincode']]
    					]
    				);
    				$manifestDetails['manifest_data'][] = [
    					'customer_order_number' => $manifestDatum['order_id'],
    					'ref_number' => $labelDataArray['order_number'],
    					'awb_number' => $labelDataArray['awb_number'],
    					'product_type' => $labelDataArray['payment'],
    					'customer_name' => $labelDataArray['name'],
    					'city' => $city->city,
    					'pincode' => $labelDataArray['pincode']
    				];
    			}
    		} else {
    			$manifestDetails['manifest_data'] = [];
    		}
    		if (isset($manifestDetails) and !empty($manifestDetails)) {
    			$insertDetails = new MerchantManifest();
    			$insertDetails->save(
    				[
    					'manifest_id' => $manifestNumber,
    					'merchant_id' => $mid
    				]
    			);
    		}
    		$this->generateManifestLabel($manifestDetails);
    		$path = __DIR__ . '/../../../../public/media/labels/' . $manifestDetails['manifest_number'] . '.pdf';
    		if (file_exists($path)) {
    			$this->response->setHeader('Content-type', 'application/pdf');
    			return $this->response->setFileToSend($path, $manifestDetails['manifest_number'] . '.pdf', false);
    		}
    	}
    }

    public function generateManifestLabel($manifestParams = [])
    {
    	try {
    		$pickupType = '';
    		if ($manifestParams['pickup_type'] == 'WH') {
    			$pickupType = 'Ware House';
    		} elseif ($manifestParams['pickup_type'] == 'SL') {
    			$pickupType = 'Seller';
    		} elseif ($manifestParams['pickup_type'] == 'RH') {
    			$pickupType = 'Regional Handover';
    		}
    		$mpdf = new Mpdf();
    		$htmlContent = "<!DOCTYPE html>
    		<html lang='en'>
    		<head>
    		<style type='text/css'>
    		.main_container {
    			width: 600px;
    			padding: 20px;
    		}
    		.general_info{
    			padding-top: 50px;
    		}
    		.manifest_data{
    			border: 1px solid;
    			border-collapse: collapse;
    		}
    		.manifest_data tr th,td{
    			border: 1px solid;
    			text-align: center;
    		}
    		</style>
    		</head>
    		<body>
    		<div class='main_container'>
    		<h1 style='text-align: center;'>MANIFEST</h1>
    		<span class='general_info' style='padding-top: 15px;'>
    		<strong>
    		Manifest Number: {$manifestParams['manifest_number']}
    		</strong>
    		</span><br>
    		<span>
    		Print Date & Time: {$manifestParams['print_date_time']}
    		</span><br>
    		<span class='general_info'>
    		Shipper Account Code: {$manifestParams['shipper_acount_code']}
    		</span><br>
    		<span class='general_info'>
    		<strong>
    		Shipper Name: {$manifestParams['shipper_name']}
    		</strong>
    		</span><br>
    		<span class='general_info'>
    		<strong>
    		Pickup Name ({$pickupType}): {$manifestParams['pickup_name']}
    		</strong>
    		</span><br>
    		<span class='general_info'>
    		Pickup Address: {$manifestParams['pickup_address']}
    		</span><br>
    		<span>
    		Pickup Pincode: {$manifestParams['pickup_pincode']}
    		</span><br>
    		<span class='general_info'>
    		Pickup Location Code: 
    		</span><br>
    		<span class='general_info'>
    		Contact Number: {$manifestParams['contact_number']}
    		</span><br>
    		<span class='general_info'>
    		<strong>
    		Carrier Name: Ecom Express Private Limited
    		</strong>
    		</span><br>
    		<p>Details of the shipments being handed over to Ecom Express Private Limited.</p>
    		<table class='manifest_data'>
    		<thead>
    		<tr>
    		<th>S. N.</th>
    		<th>Ref No.</th>
    		<th style='min-width: 120px;'>Ecom AWB No.</th>
    		<th>Cust. Order No.</th>
    		<th>Product Type</th>
    		<th>Consignee</th>
    		<th>Dest. City</th>
    		<th>Pincode</th>
    		</tr>
    		</thead>
    		<tbody>";
    		if (isset($manifestParams['manifest_data']) and !empty($manifestParams['manifest_data'])) {
    			$i = 1;
    			foreach ($manifestParams['manifest_data'] as $key => $value) {
    				$htmlContent .= "<tr>
    				<td>{$i}</td>
    				<td>{$value['ref_number']}</td>
    				<td>{$value['awb_number']}</td>
    				<td>{$value['customer_order_number']}</td>
    				<td>{$value['product_type']}</td>
    				<td>{$value['customer_name']}</td>
    				<td>{$value['city']}</td>
    				<td>{$value['pincode']}</td>
    				</tr>";
    				$i++;
    			}
    		} else {
    			$htmlContent .= "<tr>
    			<td colspan='8'>No Details</td>
    			</tr>";
    		}
    		$total = count($manifestParams['manifest_data']);
    		$htmlContent .= "
    		<tr>
    		<td colspan='8'>
    		<strong>Shipments Count: {$total}</strong>
    		</td>
    		</tr>
    		</tbody>
    		</table>
    		<p><strong>Signature & Stamp</strong></p>
    		<p>Shipper Name/Pickup Name (Seller/Vendor):</p>
    		<p>Date:</p>
    		<p></p>
    		<p>Above mentioned shipments handed over to Ecom Express Private Limited which are received by:</p>
    		<p></p>
    		<p><strong>Pick-up Staff details</strong></p>
    		<p></p>
    		<p>Employee Name:</p>
    		<p></p>
    		<p>Employee Code:</p>
    		<p></p>
    		<p>Contact Number:</p>
    		<p></p>
    		<p>Date and Time:</p>
    		</div>
    		</body>
    		</html>";

    		$mpdf->WriteHTML($htmlContent);
    		$mpdf->Output(__DIR__ . '/../../../../public/media/labels/' . $manifestParams['manifest_number'] . '.pdf', 'F');
    	} catch (\Exception $exception) {
    		$this->getDI()->get('log')->logContent(var_export($exception->getMessage(), true), 7, 'labelException.log');
    	}
    }
}
