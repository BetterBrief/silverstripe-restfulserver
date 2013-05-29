<?php

/**
 * An intermediary class for providing custom DataObject functionality via REST.
 */
abstract class RestfulServer_API extends Object {

	protected
		$data,
		$controller;

	/**
	 * @param DataObject $data The current object this sits in front of
	 * @param RestfulServer $controller The current RestfulServer instance
	 */
	public function __construct(DataObject $data, RestfulServer $controller) {
		$this->setData($data);
		$this->setController($controller);
	}
	
	public function setData(DataObject $data) {
		$this->data = $data;
		return $this;
	}

	public function getData() {
		return $this->data;
	}

	public function setController(RestfulServer $controller) {
		$this->controller = $controller;
		return $this;
	}

	public function getController() {
		return $this->controller;
	}

}
