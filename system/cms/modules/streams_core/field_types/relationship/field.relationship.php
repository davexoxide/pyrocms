<?php defined('BASEPATH') or exit('No direct script access allowed');

use Pyro\Module\Streams_core\Core\Field\AbstractField;

/**
 * PyroStreams Relationship Field Type
 *
 * @package		PyroCMS\Core\Modules\Streams Core\Field Types
 * @author		Parse19
 * @copyright	Copyright (c) 2011 - 2012, Parse19
 * @license		http://parse19.com/pyrostreams/docs/license
 * @link		http://parse19.com/pyrostreams
 */
class Field_relationship extends AbstractField
{
	public $field_type_slug			= 'relationship';

	public $db_col_type				= 'integer';

	public $custom_parameters		= array( 'choose_stream', 'link_uri' );

	public $version					= '1.1.0';

	public $author					= array('name'=>'Parse19', 'url'=>'http://parse19.com');

	// --------------------------------------------------------------------------

	/**
	 * Run time cache
	 */
	private $cache;

	// --------------------------------------------------------------------------

	/**
	 * Output form input
	 *
	 * @param	array
	 * @param	array
	 * @return	string
	 */
	public function form_output($data, $entry_id, $field)
	{
		// Get slug stream
		$stream = ci()->streams_m->get_stream($data['custom']['choose_stream']);

		if (! $stream) {
			return '<em>'.ci()->lang->line('streams:relationship.doesnt_exist').'</em>';
		}

		$title_column = $stream->title_column;

		// Default to ID for title column
		if ( ! trim($title_column) or !ci()->db->field_exists($title_column, $stream->stream_prefix.$stream->stream_slug)) {
			$title_column = 'id';
		}

		// Get the entries
		$obj = ci()->db->get($stream->stream_prefix.$stream->stream_slug);

		$choices = array();

		// If this is not required, then
		// let's allow a null option
		if ($field->is_required == 'no') {
			$choices[null] = ci()->config->item('dropdown_choose_null');
		}

		foreach ($obj->result() as $row) {
			// Need to replace with title column
			$choices[$row->id] = $row->$title_column;
		}

		// Output the form input
		return form_dropdown($data['form_slug'], $choices, $data['value'], 'id="'.rand_string(10).'"');
	}

	// --------------------------------------------------------------------------

	/**
	 * Get a list of streams to choose from
	 *
	 * @return	string
	 */
	public function param_choose_stream($stream_id = false)
	{
		$choices = array();

		// Now get our streams and add them
		// under their namespace
		$streams = ci()->db->select('id, stream_name, stream_namespace')->get(STREAMS_TABLE)->result();

		foreach ($streams as $stream) {
			if ($stream->stream_namespace) {
				$choices[$stream->stream_namespace][$stream->id] = $stream->stream_name;
			}
		}

		return form_dropdown('choose_stream', $choices, $stream_id);
	}

	// --------------------------------------------------------------------------

	/**
	 * Pre Ouput
	 *
	 * Process before outputting on the CP. Since
	 * there is less need for performance on the back end,
	 * this is accomplished via just grabbing the title column
	 * and the id and displaying a link (ie, no joins here).
	 *
	 * @param	array 	$input
	 * @return	mixed 	null or string
	 */
	public function pre_output($input, $data)
	{
		if ( ! $input) return null;

		$stream = ci()->streams_m->get_stream($data['choose_stream']);

		$title_column = $stream->title_column;

		// -------------------------------------
		// Data Checks
		// -------------------------------------

		// Make sure the table exists still. If it was deleted we don't want to
		// have everything go to hell.
		if ( ! ci()->db->table_exists($stream->stream_prefix.$stream->stream_slug)) {
			return null;
		}

		// We need to make sure the select is NOT NULL.
		// So, if we have no title column, let's use the id
		if (trim($title_column) == '') {
			$title_column = 'id';
		}

		// -------------------------------------
		// Get the entry
		// -------------------------------------

		$row = ci()->db
						->select()
						->where('id', $input)
						->get($stream->stream_prefix.$stream->stream_slug)
						->row_array();
		
		if (ci()->uri->segment(1) == 'admin')
		{
			if (isset($data['link_uri']) and ! empty($data['link_uri']))
			{
				return '<a href="'.site_url(str_replace(array('-id-', '-stream-'), array($row['id'], $stream->stream_slug), $data['link_uri'])).'">'.$row[$title_column].'</a>';
			}
			else
			{
				return '<a href="'.site_url('admin/streams/entries/view/'.$stream->id.'/'.$row['id']).'">'.$row[$title_column].'</a>';
			}
		}
		else
		{
			return $row;
		}
	}

	// --------------------------------------------------------------------------

	/**
	 * User Field Type Query Build Hook
	 *
	 * This joins our related fields so they don't have to
	 * be queried separately in pre_output_plugin. Pre_output_plugin
	 * now just formats the rows.
	 *
	 * @param 	array 	&$sql 	The sql array to add to.
	 * @param 	obj 	$field 	The field obj
	 * @param 	obj 	$stream The stream object
	 * @return 	void
	 */
	public function query_build_hook(&$sql, $field, $stream)
	{
		// Create a special alias for the users table.
		$alias = 'rel_'.$field->field_slug;

		// Make sure we have a related stream.
		if ( ! isset($field->field_data['choose_stream']) or ! $field->field_data['choose_stream']) {
			return null;
		}

		// Get our related stream.
		$rel_stream = ci()->streams_m->get_stream($field->field_data['choose_stream']);

		if (! $rel_stream) {
			return null;
		}

		// Basic fields
		$sql['select'][] = '`'.$alias.'`.`id` as `'.$field->field_slug.'||id`';
		$sql['select'][] = '`'.$alias.'`.`created` as `'.$field->field_slug.'||created`';
		$sql['select'][] = '`'.$alias.'`.`updated` as `'.$field->field_slug.'||updated`';
		$sql['select'][] = '`'.$alias.'`.`created_by` as `'.$field->field_slug.'||created_by`';
		$sql['select'][] = '`'.$alias.'`.`ordering_count` as `'.$field->field_slug.'||ordering_count`';

		// Get stream fields.
		$stream_fields = ci()->streams_m->get_stream_fields($rel_stream->id);

		foreach ($stream_fields as $field_slug => $stream_field)
		{
			if (! ci()->db->field_exists($field_slug, $rel_stream->stream_prefix.$rel_stream->stream_slug)) continue;
			
			$sql['select'][] = '`'.$alias.'`.`'.$field_slug.'` as `'.$field->field_slug.'||'.$field_slug.'`';
		}

		$sql['join'][] = 'LEFT JOIN '.ci()->db->protect_identifiers($rel_stream->stream_prefix.$rel_stream->stream_slug, true).' as `'.$alias.'` ON `'.$alias.'`.`id`='.ci()->db->protect_identifiers($stream->stream_prefix.$stream->stream_slug.'.'.$field->field_slug, true);
	}

	// --------------------------------------------------------------------------

	/**
	 * Pre Ouput Plugin
	 *
	 * This takes the data from the join array
	 * and formats it using the row parser.
	 *
	 * @param	array 	$row 		the row data from the join
	 * @param	array  	$custom 	custom field data
	 * @param	mixed 	null or formatted array
	 */
	public function pre_output_plugin($row, $custom)
	{
		if ( ! $row) return null;

		// Mini-cache for getting the related stream.
		if (isset($this->cache[$custom['choose_stream']][$row])) {
			return $this->cache[$custom['choose_stream']][$row];
		}

		// Okay good to go
		$stream = ci()->streams_m->get_stream($custom['choose_stream']);

		// Do this gracefully
		if (! $stream) {
			return null;
		}

		$stream_fields = ci()->streams_m->get_stream_fields($stream->id);

		// We should do something with this in the future.
		$disable = array();
		
		// Load our row data if needed
		if (! is_array($row) and ! is_object($row))
		{
			$row = (array) ci()->db->select()->where('id', $row)->limit(1)->get($stream->stream_prefix.$stream->stream_slug)->row(0);
		}

		return ci()->row_m->format_row($row, $stream_fields, $stream, false, true, $disable);
	}

}
