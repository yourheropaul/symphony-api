<?php
/*
 * Road to V::class.mailex.php
 * 
 * Created on Feb 11, 2009
 *
 */
 
require_once(WORKSPACE . '/api/class.databasemanipulator.php');
 
class MailEx
{
	const	REPLACEMENT_EXPRESSION = '/\{\$(.+)\}/';
	
	private $_section_id 		 = null;
	private $_fields		  	 = null;
	private $_transformed_fields = null;
	private $_transformations	 = null;
	
	private static $default_fields = array("handle", "title", "body");

	/*
	** Dynamic constrction
	*/
				
	public function __construct( $parent, $section_name, $entry_handle, $field_names = null )
	{
		if (!$section_name || !$entry_handle || !$parent)
			throw new Exception(__CLASS__ . ":: required constructor arguments not passed");
		
		// Assign defaults	
		if (!$field_names) $field_names = self::$default_fields;
		
		// Set up the manipulator
		DatabaseManipulator::associateParent($parent);
		
		// This can be either a handle or a full name
		$this->_section_id = DatabaseManipulator::getSectionIDByName( $section_name );
		
		// Get a list of ids mapped to handles
		$field_ids = DatabaseManipulator::getFieldIDsByName( $field_names, $this->_section_id );
		
		// Get all the entries for the section where the first field name matches entry_handle
		$this->_fields = DatabaseManipulator::getEntries( $this->_section_id, $field_ids, reset($field_names), $entry_handle );				
	}
	
	/*
	** Process the email section's templating values
	*/
				
	public function transform( $transformations )
	{	
		$this->_transformations = $transformations;
	
		foreach ($this->_fields as $key => $value)
			$this->_transformed_fields[$key] = preg_replace_callback(self::REPLACEMENT_EXPRESSION, array($this, "callback"), $value['value']);
	}		
	
	/*
	** Send out the email
	*/
	
	public function send( $adressee, $sender )
	{
		if (!$this->_transformed_fields)
			throw new Exception(__CLASS__ . ":: transformation has not been run");
			
		$admin_email = $sender;
		
		$headers  = 'MIME-Version: 1.0' . "\r\n" .
					'Content-type: text/html; charset=iso-8859-1' . "\r\n" .
					'From: '.$admin_email."\r\n" .
      				'Reply-To: '.$admin_email;
 
		return mail($adressee, $this->_transformed_fields['title'], $this->_transformed_fields['body'], $headers);
	}
	
	// Callback for preg_replacement
	private function callback( $matches )
	{
		return $this->_transformations[$matches[1]];
	}
}
?>
