<?php

/*
 * class ShrimpTest_Variant
 * Custom variants must extend this class.
 */
class ShrimpTest_Variant {

	var $name, $code;

	function ShrimpTest_Variant( ) {
	}

	var $shrimp, $model, $interface;
	function set_shrimp( $shrimptest_instance ) {
		// setup some nice aliases
		$this->shrimp =& $shrimptest_instance;
		$this->model =& $shrimptest_instance->model;
		$this->interface =& $shrimptest_instance->interface;
	}
}
