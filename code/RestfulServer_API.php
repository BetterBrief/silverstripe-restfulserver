<?php

/**
 * An intermediary class for providing custom DataObject functionality via REST.
 */
abstract class RestfulServer_API extends Object {

	protected
		$data,
		$controller;

	protected static
		/**
		 * Define and override these. Example:
		 * 	'method' => 'POST',			// limit this to a request method
		 *	'instance' => true,			// is an instantiated object required
		 *	'params' => array(			// parameters to be passed to the method
		 *		'NonRequiredParam' => false,
		 *		'RequiredParam' => true,
		 *	),
		 *	'permissions' => array('canEdit'), // required can* permissions
		 */
		$api_params = array();

	/**
	 * @param DataObject $data The current object this sits in front of
	 * @param RestfulServer $controller The current RestfulServer instance
	 */
	public function __construct(DataObject $data, RestfulServer $controller) {
		$this->setData($data);
		$this->setController($controller);
	}

	/**
	 * Bootstrap an API wrapper function and then run it.
	 * Parses and validates against any params.
	 */
	public function handleAction($action, $controller) {
		$params = array(); // generate these from the static
		$classParams = static::$api_params;
		if(isset($classParams[$action])) {
			$apiParams = $classParams[$action];
			if(isset($apiParams['instance'])) {
				$instanceRequired = $apiParams['instance'];
				if($instanceRequired === true && !$this->data->exists()) {
					return $controller->notFound();
				}
			}
			if(isset($apiParams['method'])) {
				$method = strtoupper($apiParams['method']);
				if($controller->request->httpMethod() != $method) {
					return $controller->notFound();
				}
			}
			if(isset($apiParams['params'])) {
				$paramSettings = $apiParams['params'];
				$params = $this->controller->getPayloadArray();
				$params = array_intersect_key($params, $paramSettings);
				// validate against params
				$result = $this->validate($paramSettings, $params);
				if(!$result->valid()) {
					return $result;
				}
			}
		}
		return $this->$action($controller, $params);
	}
	
	public function setData(DataObject $data) {
		$this->data = $data;
		return $this;
	}

	public function getData() {
		return $this->data;
	}

	/**
	 * Validates against a list of parameters using the current request method
	 * @param array $settings Array of field name/boolean required
	 * @param array $params Array of fields from the request
	 * @return ValidationResult
	 */
	public function validate($settings, $params) {
		$result = new ValidationResult();
		foreach($settings as $name => $required) {
			if($required) {
				if(empty($params[$name])) {
					$result->error($name);
				}
				continue;
			}
		}
		return $result;
	}

	public function setController(RestfulServer $controller) {
		$this->controller = $controller;
		return $this;
	}

	public function getController() {
		return $this->controller;
	}

}
