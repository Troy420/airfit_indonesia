<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
	use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

	protected $data = [];
	protected $uploadsFolder = 'uploads/';

	protected $rajaOngkirApiKey = null;
	protected $rajaOngkirBaseUrl = null;
	protected $rajaOngkirOrigin = null;

	protected $couriers = [
	    // $code => $courier
		'jne' => 'JNE',
		'pos' => 'POS Indonesia',
		'tiki' => 'Titipan Kilat'
	];

	protected $provinces = [];

	public function __construct() {
		$this->rajaOngkirApiKey = env('RAJAONGKIR_API_KEY');
		$this->rajaOngkirBaseUrl = env('RAJAONGKIR_BASE_URL');
		$this->rajaOngkirOrigin = env('RAJAONGKIR_ORIGIN');

		$this->initAdminMenu();
	}

	private function initAdminMenu() {
		$this->data['currentAdminMenu'] = 'dashboard';
		$this->data['currentAdminSubMenu'] = '';
	}

	protected function loadTheme($view, $data = [])
	{
		return view('themes/'. env('APP_THEME') .'/'. $view, $data);
	}

	protected function rajaOngkirRequest($resource, $params = [], $method = 'GET')
	{
		$client = new \GuzzleHttp\Client();

		$headers = ['key' => $this->rajaOngkirApiKey];
		$requestParams = [
			'headers' => $headers,
		];

		//$resource here is ProvinceId
		$url = $this->rajaOngkirBaseUrl . $resource;
		if ($params && $method == 'POST') {
			$requestParams['form_params'] = $params;
		} else if ($params && $method == 'GET') {
			$query = is_array($params) ? '?'.http_build_query($params) : '';
			$url = $this->rajaOngkirBaseUrl . $resource . $query;
		}

		// by default the $method is GET
		$response = $client->request($method, $url, $requestParams);

		return json_decode($response->getBody(), true);

	}

	protected function getProvinces()
	{
        // file path
		$provinceFile = 'provinces.txt';

		// save to storage/app/uploads/files/provinces.txt
		$provinceFilePath = $this->uploadsFolder. 'files/' . $provinceFile;

		// does it exist in the file path
		$isExistProvinceJson = \Storage::disk('local')->exists($provinceFilePath);

		// get the province in RajaOngkirRequest
        $response = $this->rajaOngkirRequest('province');

        // if not create a new file
		if (!$isExistProvinceJson){
		    // get the list
			$response = $this->rajaOngkirRequest('province');

			// save file to storage/app/uploads/files/provinces.txt
			\Storage::disk('local')->put($provinceFilePath, serialize($response['rajaongkir']['results']));
		}

		$province = unserialize(\Storage::get($provinceFilePath));
//		What unserialize do is:
//        a:34:{i:0;a:2:{s:11:"province_id";s:1:"1";s:8:"province";s:4:"Bali";}
//        array:34 [▼
//          0 => array:2 [▼
//          "province_id" => "1"
//          "province" => "Bali"
//        ]
//          1 => array:2 [▶]

        // create an empty array
		$provinces = [];
		// if it is not empty
		if(!empty($province)){
			foreach($province as $province){

			    // $provinces = "province" => "Bali" ,, this is a for loop
				$provinces[$province['province_id']] = strtoupper($province['province']);
			}
		}

		return $provinces;
	}

	protected function getCities($provinceId){
	    //name of the cities file .txt
		$cityFile = 'cities_at_'. $provinceId .'.txt';

		// the save file path
		$cityFilePath = $this->uploadsFolder. 'files/' .$cityFile;

		// check if the file already exists or not?
		$isExistCitiesJson = \Storage::disk('local')->exists($cityFilePath);

		// if it does not exists,
		if (!$isExistCitiesJson){
		    // go to rajaOngkirRequest and get the city with (provinceId) provided
			$response = $this->rajaOngkirRequest('city', ['province' => $provinceId]);

			// and save it to the filepath
			\Storage::disk('local')->put($cityFilePath, serialize($response['rajaongkir']['results']));
		}

		// get the cityFilePath and unserialize it (turn to array)
		$cityList = unserialize(\Storage::get($cityFilePath));

		// create an empty array
		$cities = [];

		// if the array is not empty
		if(!empty($cityList)){
		    // foreach file path as $city
			foreach($cityList as $city){
			    // put it in the array
                // $city['type'] = Kota
                // $city['city_name'] = Jakarta Barat/Timur/etc
				$cities[$city['city_id']] = strtoupper($city['type'] . ' ' . $city['city_name']);
			}
		}

		return $cities;
	}

	protected function initPaymentGateway(){
		// Set your Merchant Server Key
		\Midtrans\Config::$serverKey = env('MIDTRANS_SERVER_KEY');
		// Set to Development/Sandbox Environment (default). Set to true for Production Environment (accept real transaction).
		\Midtrans\Config::$isProduction = false;
		// Set sanitization on (default)
		\Midtrans\Config::$isSanitized = true;
		// Set 3DS transaction for credit card to true
		\Midtrans\Config::$is3ds = true;
	}

}
