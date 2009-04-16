<?php
/*
 * SymphonyEx::class.databasemanipulator.php
 * 
 * Created on Feb 11, 2009
 *
 * FIXME: use the ASDC class!
 */
 
 if(!defined('__IN_SYMPHONY__')) die('<h2>Error</h2><p>You cannot directly access this file</p>');

/*
** Include the symphony core stuff
*/
 
require_once(TOOLKIT . '/class.entrymanager.php');
require_once(TOOLKIT . '/class.sectionmanager.php');

class DatabaseManipulator
{
	// Symphony entity managers
	private static	$em=null, $sm=null, $ASDC=null;
	

	
	/*
	** Associate the object with a Symphony _Parent  
	*/
	
	public static function associateParent( $parent, $initialise_em=true, $initialise_sm=true )
	{		
		$ASDC_locations = array(
									EXTENSIONS . '/asdc/lib/class.asdc.php',
									WORKSPACE . "/api/class.asdc.php"
								);
								
		// Plug in in the ASDC class		
		foreach ($ASDC_locations as $location)
		{
			if (file_exists($location))	
			{
				require_once($location);
				break;
			}
		}
		
		self::$ASDC = ASDCLoader::instance();
		
		// Standard symphony init 
		if ($initialise_sm) self::$sm = new SectionManager($parent);
		if ($initialise_em) self::$em = new EntryManager($parent);						
	}
	
	/*
	** Check is the objects are intiialised 
	*/
	
	public static function isInitialised( $check_sm=true, $check_em=true )
	{
		return ( ($check_em ? ( self::$em ? true : false) : true) && ($check_sm ? ( self::$sm ? true : false) : true));
	}
	
	/*
	** Get a section ID by name using managers
	*/
	
	public static function getSectionIDByName( $section_name )
	{
		self::_assert(false, true);
		
		if (is_array( $section_name ))
		{
			$ret = array();
			
			foreach ($section_name as $name)
				$ret[$name] = self::$sm->fetchIDFromHandle(Lang::createHandle($name));
			
			return $ret;
		}
		
		return self::$sm->fetchIDFromHandle(Lang::createHandle($section_name));
	}
	
	/*
	** Returns an array of field names mapped to IDs
	*/
	
	public static function getFieldIDsByName( $field_names, $section_id )
	{
		self::_assert();
		
		if (!is_numeric($section_id))
			$section_id = self::getSectionIDByName($section_id);		
		
		// Retun array
		$field_ids = array();
		
		// Get the section entry
		$fields = end(self::$sm->fetch($section_id));
							
		if (is_array($field_names))
		{								
			foreach ( $field_names as $key )
				$field_ids[$key] = $fields->fetchFieldIDFromElementName($key, $section_id);
		}		
		else if ($field_names=="*")
		{
			foreach ($fields->fetch(null, $section_id) as $field)
			{
				$tmp = $field->get();				
				$field_ids[$tmp['element_name']] = $tmp['id'];
			}						
		}
			
		return $field_ids;	
	}
	
	/*
	** Get a single field IS by name 
	*/
	
	public static function getFieldIDByName( $field_name, $section_id )
	{
		$fields = self::getFieldIDsByName( "*", $section_id );							
		
		return $fields[$field_name];
	}		
	
	/*
	** Fetch entries from a section, returning their associated counts 
	** filter argument is an array which matches field names to values
	**   :: array ("name" => "mr. stanfont")
	**   
	**  Or, in the case of section links
	**   :: array ("parent-band:relation_id" => $band_id)
	*/
	
	public static function getEntries( $section_id, $field_ids=null, $filter=null, $conditions=null, $callback=null )
	{
		self::_assert();
		
		$mem_start = memory_get_usage();
		
		// Perform all conditional replacements here		
		$limit = null;
		$start = null;
		${'show-counts'} = null;
				
		// Assume the conditions data is to be trusted
		if ($conditions)
			extract($conditions, EXTR_IF_EXISTS);
			
		// non-conditional variables
		if (!is_numeric($section_id))
			$section_id = self::getSectionIDByName($section_id);
			
		// field data
		if ($field_ids)
			$field_ids = self::getFieldIDsByName($field_ids, $section_id);
			
		// Filtering
		$where = NULL;
		$joins = NULL;
		$entry_id = null;										
		
		if ($filter)
		{
			$processed_filters = array();					
			
			foreach ($filter as $key => $value)
			{
				if ($key == 'system:id')
				{
					$entry_id = $value;					
					continue;
				}
				
				list($field_name, $column_name) = explode(':', $key);
				
				if (!is_numeric($field_name))
					$field_name = self::getFieldIDByName($field_name, $section_id);					

				/*
				** Generate the SQL 'where' string, and modify if there a col name  
				*/					
				
				if (!$field = self::$em->fieldManager->fetch($field_name))
					throw new Exception(__CLASS__ . ":: field $field_name does not exist!");
				
				if (!method_exists($field, 'buildDSRetrivalSQL'))
					throw new Exception(__CLASS__ . ":: field SQL cannot be retrieved - method doesn't exist");
				
				// A new storage variable for each iteration
				$_where = null;
				$_joins = null;
				
				// Get the WHERE and JOIN
				$field->buildDSRetrivalSQL(array($value), $_joins, $_where, true);
				
				// Get the right table alias
				$table_name = self::refineTableName($_joins);
				
				// Continue the join
				$joins .= $_joins;
						
				// Process the WHEEE conditions if necessary			
				if ($column_name)
				{
					if (!table_name) throw new Exception("table name not found!");
												
					$_where = " AND ( {$table_name}.{$column_name} = '{$value}' )";
				}																										
					
				$where .= $_where;
			}
		}
		
		// No need for a return if there's a callback	
		if (!$callback)
			$entries = array();
		
		$ASDCResult = self::$ASDC->query(self::fetchSQLQuery($entry_id, $section_id, $limit, $start, $where, $joins));
	
		for (; $ASDCResult->valid(); $ASDCResult->next())
		{
			$current = $ASDCResult->current();
			
			// Ugly method of grabbing the entry's data
			$entry = reset(self::$em->__buildEntries(array((array)$current), $section_id));
			
			// Handle the callback
			if ($callback)
			{
				$fields = null;
				
				if ($field_ids)
				{
					$fields = array();
					
					foreach ($field_ids as $key => $value)
						$fields[$key] = $entry->getData($value);
				}										
						
				call_user_func($callback, $entry->get('id'), $fields, $entry->fetchAllAssociatedEntryCounts());	
			}						
			else
			{							
				//if (${'show-counts'})					
					$entries[(int)$entry->get('id')]['counts'] = $entry->fetchAllAssociatedEntryCounts();
				
				if ($field_ids)
					foreach ($field_ids as $key => $value)
						$entries[(int)$entry->get('id')]['fields'][$key] = $entry->getData($value);			
			}
		}			
		
		/*
		foreach (self::$em->fetch($entry_id, $section_id, $limit, $start, $where, $joins) as $entry)
		{	
			//if (${'show-counts'})					
				$entries[(int)$entry->get('id')]['counts'] = $entry->fetchAllAssociatedEntryCounts();
			
			if ($field_ids)
				foreach ($field_ids as $key => $value)
					$entries[(int)$entry->get('id')]['fields'][$key] = $entry->getData($value);			
		}		

		var_dump(memory_get_usage() -  $mem_start); */
		
		if (!$callback)
			return $entries;
		else
			return true;
	}
	
	/*
	** Get a section entry by ID
	*/		
	
	public static function getEntryById( $section_id, $entry_id, $field_ids=null )
	{
		self::_assert();
		
		if (!is_numeric($section_id))
			$section_id = self::getSectionIDByName($section_id);
					
		if (!$field_ids)
			return end(self::$em->fetch($entry_id))->getData();
		else
		{					
			// Single dimension arrays need to be hooked up to field IDs
			if (!is_array(reset($field_ids)))
				$field_ids = self::getFieldIDsByName($field_ids, $section_id);			
			
			$fields = array();
			
			$entry = end(self::$em->fetch($entry_id));
			
			$fields['system:id'] = (int)$entry->get('id');						
	
			foreach ($field_ids as $key => $value)
				$fields[$key] = $entry->getData($field_ids[$key]);
			
			return $fields;
		}
	}
	
	/*
	** Delete an entry 
	*/
	
	public static function deleteEntries( $entries )
	{
		return self::$em->delete($entries);
	}	
	
	public static function rawASDCQuery( $query )
	{
		self::_assert(false, false, true);
		
		return self::$ASDC->query( $query );
	}
	
	
	/*
	** Private methods 
	*/
	
	// Generate the standard Symphony query, slightly simplified from entrymanager
	private static function fetchSQLQuery($entry_id = null, $section_id = null, $limit=NULL, $start=NULL, $where=NULL, $joins=NULL, $group=false, $sort_order="ASC")
	{
		if(!$entry_id && !$section_id)
			throw new Exception(__CLASS__ . "::" . __FUNCTION__ ." - neither entry_id or section_id provided");
		
		if ($entry_id && !is_array($entry_id)) $entry_id = array($entry_id);	
				
		// Currently we only sort by date
		$sort = 'ORDER BY ' . ($sort_order != 'RAND' ? "`e`.`creation_date` $sort_order" : 'RAND() ');
			
		$sql = "				
				SELECT  ".($group ? 'DISTINCT ' : '')."`e`.id, 
						`e`.section_id, e.`author_id`, 
						UNIX_TIMESTAMP(e.`creation_date`) AS `creation_date`, 
						UNIX_TIMESTAMP(e.`creation_date_gmt`) AS `creation_date_gmt`
						
				FROM `tbl_entries` AS `e`
				$joins		
				WHERE 1
				".($entry_id ? "AND `e`.`id` IN ('".@implode("', '", $entry_id)."') " : '')."
				".($section_id ? "AND `e`.`section_id` = '$section_id' " : '')."
				$where
				$sort" .
						"
				".($limit ? 'LIMIT ' . intval($start) . ', ' . intval($limit) : '')."
				
			";						

		return $sql;			
	}
		
	// Ensure the properties are set
	private static function _assert( $check_em=true, $check_sm=true, $check_asdc=true )
	{
		if (($check_em && !self::$em) || ($check_sm && !self::$sm) || ($check_asdc && !self::$ASDC))
			throw new Exception(__CLASS__ . ":: assert() failed");
	}
	
	// get a handle
	private static function getHandle( $inp )
	{
		if (isset($inp['handle']))
			return $inp["handle"];
		else
			return $inp['value'];
	}
	
	// Refine a table name from a join statement
	private static function refineTableName( $join )
	{				
		$matches = array();			
		
		if (!preg_match('/LEFT JOIN (`|\'|")(?P<table>[^`\'"]+)(`|\'|") AS (`|\'|")(?P<alias>[^`\'"]+)(`|\'|")/', $join, $matches))
			return null;
													
		return $matches['alias'];
	}
} 
 
?>
