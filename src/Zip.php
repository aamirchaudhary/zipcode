<?php

namespace PragmaRX\Zip;

use PragmaRX\Zip\Exceptions\InvalidZip;
use PragmaRX\Zip\Exceptions\WebServicesNotFound;
use PragmaRX\Zip\Support\Http;
use PragmaRX\Zip\Support\Result;
use PragmaRX\Zip\Support\Country;

class Zip
{
	/**
	 * The HTTP class.
	 *
	 * @var Support\Http
	 */
	private $http;

	/**
	 * The list of web services.
	 *
	 * @var
	 */
	private $webServices;


	/**
	 * The country object, which also holds the related web services.
	 *
	 * @var Support\Country
	 */
	private $country;
	/**
	 * The current zip being searched.
	 *
	 * @var
	 */
	private $zip;

	/**
	 * The current result found.
	 *
	 * @var
	 */
	private $result;

	/**
	 * The list of errors.
	 *
	 * @var array
	 */
	private $errors = [];

	/**
	 * The class constructor.
	 *
	 * @param Http $http
	 */
	public function __construct(Http $http = null)
	{
		$this->http = ! $http
						? new Http()
						: $http;

		$this->result = new Result();

		$this->country = new Country();

		$this->setCountry('BR');
	}

	/**
	 * Zip setter & validate zip.
	 *
	 * @param $zip
	 * @return bool
	 */
	public function setZip($zip)
	{
		$this->clearErrors();

		$this->zip = $this->validateZip($zip);
	}

	/**
	 * Zip validator.
	 *
	 * @param $zip
	 * @throws Exceptions\InvalidZip
	 * @return bool
	 */
	public function validateZip($zip)
	{
		$zip = $this->clearZip($zip);

		if ($this->getZipLength() && strlen($zip) !== $this->getZipLength())
		{
			throw new InvalidZip;
		}

		return $zip;
	}

	/**
	 * Check if at least one web service is up.
	 *
	 * @return bool
	 */
	public function checkZipWebServices()
	{
		foreach($this->getWebServices() as $webService)
		{
			if ($this->http->ping($webService->getUrl()))
			{
				return true;
			}
		}

		$this->addError('No zip webServices are up.');

		return false;
	}

	/**
	 * WebServices setter.
	 *
	 * @param $webServices
	 */
	public function setWebServices($webServices)
	{
		$this->country->getWebServices()->setWebServices($webServices);
	}

	/**
	 * WebServices getter.
	 *
	 * @return array
	 */
	public function getWebServices()
	{
		return $this->country->getWebServices();
	}

	/**
	 * Find an result by zip.
	 *
	 * @param $zip
	 * @return bool|void
	 */
	public function findZip($zip)
	{
		foreach($this->getWebServices() as $webService)
		{
			if ($result = $this->searchZipUsingWebService($zip, $webService))
			{
				return $this->getResult();
			}
		}

		$this->addError('There are no webServices available.');

		return false;
	}

	/**
	 * Search a zip via HTTP.
	 *
	 * @param $zip
	 * @param $webService
	 * @internal param $url
	 * @internal param $query
	 * @internal param $format
	 * @return array|bool
	 */
	public function gatherInformationFromZip($zip, $webService)
	{
		$url = $this->buildUrl($zip, $webService->getUrl(), $webService->getQuery(), $webService->getZipFormat());

		if ($result = $this->http->consume($url))
		{
			$result['zip'] = ! isset($result['zip']) || empty($result['zip'])
								? $zip 
								: $result['zip'];

			$result['country_id'] = ! isset($result['country_id']) || empty($result['country_id'])
									? $this->country->getId()
									: $result['country_id'];

			$result['web_service'] = $webService->getName();
		}

		return $result;
	}

	/**
	 * Clear a zip string.
	 *
	 * @param $zip
	 * @return mixed
	 */
	public function clearZip($zip)
	{
		return $zip = preg_replace("/[^0-9A-Za-z]/", "", $zip);
	}

	/**
	 * Result getter.
	 *
	 * @return mixed
	 */
	public function getResult()
	{
		return $this->result;
	}

	/**
	 * Result setter.
	 *
	 * @param mixed $result
	 * @param $webService
	 * @return mixed
	 */
	public function setResult($result, $webService)
	{
		if ( ! $result = $this->extractResultFields($result, $webService))
		{
			return false;
		}

		return $this->result->parse($result, $webService->getFields());
	}

	/**
	 * Zip getter.
	 *
	 * @return mixed
	 */
	public function getZip()
	{
		return $this->zip;
	}

	/**
	 * Build a web service url.
	 *
	 * @param $zip
	 * @param $url
	 * @param $query
	 * @param $format
	 * @return string
	 */
	private function buildUrl($zip, $url, $query, $format)
	{
		return sprintf("$url$query", $this->formatZip($this->clearZip($zip), $format));
	}

	/**
	 * Errors getter.
	 *
	 * @return mixed
	 */
	public function getErrors()
	{
		return $this->errors;
	}

	/**
	 * Extract all fields result from a result.
	 *
	 * @param $result
	 * @param $webService
	 * @return array|bool
	 */
	private function extractResultFields($result, $webService)
	{
		if ( ! $this->isValidResult($result, $webService))
		{
			return false;
		}

		$array = [];

		foreach($webService->getFields() as $field => $originalName)
		{
			if ($originalName)
			{
				$value = array_get($result, $originalName);

				$array[$field] = $value;
			}
		}

		return $array;
	}

	/**
	 * Check if an result is valid.
	 *
	 * @param $result
	 * @param $webService
	 * @return bool
	 */
	private function isValidResult($result, $webService)
	{
		$valid = true;

		foreach($webService->getFields() as $field => $originalName)
		{
			if ($originalName)
			{
				if ($webService->getField($field))
				{
					$has = array_get($result, $originalName);

					$valid = $valid && $has;

					if ( ! $has)
					{
						$this->addError("Result field '$field' was not found.");
					}
				}
			}
		}

		if ($valid)
		{
			foreach($webService as $key => $field)
			{
				if (substr($key, 0, 7) == '_check_')
				{
					$field = substr($key, 7);

					if ( ! $valid = $valid && $result[$field] == $webService->getField($key))
					{
						$this->addError("Verification field $key should be '".$webService->getField($key)."' and is '$result[$field]'.");
					};
				}
			}
		}

		if ( ! $valid)
		{
			$this->addError('Result is not valid.');
		}

		return $valid;
	}

	/**
	 * Add an error to the list of errors.
	 *
	 * @param $error
	 */
	private function addError($error)
	{
		$this->errors[] = $error;
	}

	/**
	 * Clear the errors array.
	 *
	 */
	private function clearErrors()
	{
		$this->errors = [];
	}

	/**
	 * A general search zip by web service method.
	 *
	 * @param $zip
	 * @param $webService
	 * @return bool|mixed
	 */
	public function searchZipUsingWebService($zip, $webService)
	{
		$this->setZip($zip);

		if ($result = $this->gatherInformationFromZip($this->getZip(), $webService))
		{
			if ($this->setResult($result, $webService))
			{
				return $this->getResult();
			}
		}

		return false;
	}

	/**
	 * Format a zip string.
	 *
	 * @param $zip
	 * @param $format
	 * @return string
	 */
	public function formatZip($zip, $format)
	{
		return format_masked($this->clearZip($zip), $format);
	}

	/**
	 * Country getter.
	 *
	 * @return string
	 */
	public function getCountry()
	{
		return $this->country;
	}

	/**
	 * Country setter.
	 *
	 * @param string $country
	 */
	public function setCountry($country)
	{
		$this->country->setId($country);

		$this->country->absorbCountryData($this->loadWebServices($country));
	}

	/**
	 * Get the current country zip lenght.
	 *
	 * @return mixed
	 */
	private function getZipLength()
	{
		return $this->country->getZipLength();
	}

	/**
	 * Load all web services for a country.
	 *
	 * @param $country
	 * @return mixed
	 * @throws Exceptions\WebServicesNotFound
	 */
	private function loadWebServices($country)
	{
		$file = __DIR__."/Support/WebServices/Countries/$country.php";

		if ( ! file_exists($file))
		{
			throw new WebServicesNotFound("There are no web services for this country '$country'.", 1);
		}

		try
		{
			return require($file);
		}
		catch(\Exception $e)
		{
			throw new WebServicesNotFound("Error loading web services for country country '$country': ".$e->getMessage(), 1);
		}
	}

	/**
	 * Preferred web service setter.
	 *
	 * @param $service
	 */
	public function setPreferredWebService($service)
	{
		$this->country->getWebServices()->setPreferredWebService($service);
	}

	/**
	 * User agent setter.
	 *
	 * @param $userAgent
	 */
	public function setUserAgent($userAgent)
	{
		$this->http->setUserAgent($userAgent);
	}

	/**
	 * User agent getter.
	 *
	 * @return mixed
	 */
	public function getUserAgent()
	{
		return $this->http->getUserAgent();
	}

	/**
	 * Add a web service to the list of web services.
	 *
	 * @param $webService
	 */
	public function addWebService($webService)
	{
		return $this->country->getWebServices()->addWebService($webService);
	}

	/**
	 * Get a web service by name.
	 *
	 * @param $name
	 * @return mixed
	 */
	public function getWebServiceByName($name)
	{
		return $this->country->getWebServices()->getWebServiceByName($name);
	}

	/**
	 * Remove all web services from the list of web services.
	 *
	 */
	public function clearWebServicesList()
	{
		$this->country->getWebServices()->clearWebServicesList();
	}
}
