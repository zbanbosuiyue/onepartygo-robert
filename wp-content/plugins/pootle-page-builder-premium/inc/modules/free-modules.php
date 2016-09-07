<?php
class pootle_page_builder_Unsplash {

	public $class = 'Pootle_Page_Builder';

	/** @var pootle_page_builder_Unsplash Instance */
	private static $_instance = null;

	/**
	 * Gets pootle_page_builder_Unsplash instance
	 * @return pootle_page_builder_Unsplash instance
	 * @since 	1.0.0
	 */
	public static function instance() {
		if ( null == self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	} // End instance()

	/**
	 * PootlePB_Meta_Slider constructor.
	 */
	function __construct() {
		add_action( 'init', array( $this, 'init', ) );
	}

	function init() {
		if ( class_exists( $this->class ) ) {
			// Adding modules to live editor sidebar
			add_action( 'pootlepb_modules', array( $this, 'module' ), 25 );
		}
	}

	public function module( $mods ) {
		$mods["hero-section"] = array(
			'label' => 'Hero Section',
			'icon_class' => 'dashicons dashicons-archive',
			'tab' => "#pootlepb-background-row-tab",
			'callback' => 'heroSection',
			'ActiveClass' => $this->class,
			'priority' => 5
		);
		$mods["unsplash"] = array(
			'label' => 'Unsplash image',
			'icon_class' => 'dashicons dashicons-camera',
			'callback' => 'unsplash',
			'ActiveClass' => $this->class,
			'priority' => 15
		);
		$mods["image"] = array(
			'label' => 'Image',
			'icon_class' => 'dashicons dashicons-format-image',
			'callback' => 'image',
			'ActiveClass' => $this->class,
			'priority' => 15
		);
		$mods["pbtn"] = array(
			'label' => 'Button',
			'icon_class' => 'dashicons dashicons-upload',
			'callback' => 'button',
			'ActiveClass' => $this->class,
			'priority' => 20
		);
		return $mods;
	}
}

pootle_page_builder_Unsplash::instance();