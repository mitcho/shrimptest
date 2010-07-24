<?php

/*
 * class ShrimpTest_Metric
 * Custom metrics must extend this class.
 */
class ShrimpTest_Metric {

	var $name, $code;
	var $_default = false;

	function ShrimpTest_Metric( ) {
	}

	var $shrimp, $model, $interface;
	function set_shrimp( $shrimptest_instance ) {
		// setup some nice aliases
		$this->shrimp =& $shrimptest_instance;
		$this->model =& $shrimptest_instance->model;
		$this->interface =& $shrimptest_instance->interface;
	}

}
