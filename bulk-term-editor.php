<?php
/*
Plugin Name: Bulk Term Editor
Plugin URI: http://www.560designs.com/development/bulk-term-editor.html
Description: You can register or edit terms in bulk. Copy cells in the spreadsheet, all that remains is to paste to this plugin.
Version: 1.1.1
Author: Yuya Hoshino
Author URI: http://www.560designs.com/
Text Domain: bulk-term-editor
Domain Path: /languages
*/

/*  Copyright 2016 Yuya Hoshino (email : y.hoshino56@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

class Bulk_Term_Editor {
	const DOMAIN = 'bulk-term-editor';
	public $taxonomy;
	public $updated;
	public $error;
	public $term_order_exists;
	
	public function __construct() {
		load_plugin_textdomain( self::DOMAIN, false, plugin_basename( dirname ( __FILE__ ) ) . '/languages' );
		add_action( 'admin_menu', array ( $this, 'bte_admin_menu' ) );
		add_action( 'wp_ajax_bulk_term_editor', array ( $this, 'bte_ajax' ) );
		add_filter( 'get_terms_orderby', array ( $this, 'bte_get_terms_orderby' ), 10, 3 );
		
		global $wpdb;
		$result = $wpdb->get_results( "
			SHOW COLUMNS FROM $wpdb->terms WHERE Field = 'term_order'
		" );
		$this->term_order_exists = $result ? true : false;
    }
	
	public function bte_admin_menu() {
		$hook_suffix = add_submenu_page( 'tools.php', 'Bulk Term Editor', 'Bulk Term Editor', 'administrator', 'bulk_term_editor', array ( $this, 'bte_front_page' ) );
		add_action( 'admin_print_scripts-' . $hook_suffix, array ( $this, 'bte_scripts' ) );
		add_action( 'admin_print_styles-' . $hook_suffix, array ( $this, 'bte_styles' ) );
	}
	
	public function bte_scripts() {
		wp_enqueue_script( 'bte-scripts', plugins_url( 'js/scripts.js', __FILE__ ) );
		wp_localize_script( 'bte-scripts', 'BTEAjaxObject', array ( 'ajax_url' => admin_url( 'admin-ajax.php' ) ) );
	}
	
	public function bte_styles() {
		wp_enqueue_style( 'bte-styles', plugins_url( 'css/styles.css', __FILE__ ) );
	}
	
	public function bte_json( &$rows, $term, $index ) {
		$args = array (
			'hide_empty' => false,
			'parent' => (int) $term->term_id,
		);
		if ( $this->term_order_exists )
			$args['orderby'] = 'term_order';
		$terms = get_terms( $this->taxonomy, $args );
		if ( $terms && !is_wp_error( $terms ) ) {
			$index++;
			foreach ( $terms as $term ) {
				$tab = str_repeat ( "\t", $index );
				$rows .= esc_html( $term->slug ) . $tab . esc_html( $term->name ) . "\n";
				$this->bte_json( $rows, $term, $index );
			}
		}
	}
	
	public function bte_ajax() {
		extract ( $_POST );
		$this->taxonomy = esc_html( $taxonomy );
		$rows = '';
		
		$terms = get_terms( $taxonomy, 'get=all' . ( $this->term_order_exists ? '&orderby=term_order' : '' ) );
		if ( $terms && !is_wp_error( $terms ) ) {
			$index = 1;
			foreach ( $terms as $term ) {
				if ( !$term->parent ) {
					$rows .= esc_html( $term->slug ) . "\t" . esc_html( $term->name ) . "\n";
					$this->bte_json( $rows, $term, $index );
				}
			}
		}
		
		$json = json_encode ( $rows );
		header ( 'Content-Type: application/json; charset=utf-8' );
		echo $json;
		wp_die();
	}
	
	public function bulk_term_editor_action_js() {
?>
<script type="text/javascript">
jQuery(function($) {
<?php if ( $this->taxonomy ) : ?>
<?php if ( $this->updated ) : ?>
	bte_get_bte_field('<?php echo $this->taxonomy; ?>');
<?php endif; ?>
	$('#bulk_term_editor input.return').on('click.bte_return', function() {
		bte_get_bte_field('<?php echo $this->taxonomy; ?>');
		return false;
	});
<?php endif; ?>
});
</script>
<?php
	}
	
	public function bte_get_terms_orderby( $orderby, $args, $taxonomy ) {
		if ( isset ( $args['orderby'] ) && $args['orderby'] == 'term_order' )
			$orderby = 't.term_order';
		return $orderby;
	}
	
	public function bte_front_page() {
		global $wpdb;
		
		if ( !empty ( $_POST['mode'] ) && $_POST['mode'] == 'add' ) {
			extract ( $_POST );
			
			if ( empty ( $taxonomy ) )
				$this->error .= '<p>' . __( 'Select a taxonomy.', self::DOMAIN ) . '</p>';
			else
				$this->taxonomy = $taxonomy;
			
			if ( empty ( $terms_plain_text ) ) {
				$this->error .= '<p>' . __( 'Enter terms.', self::DOMAIN ) . '</p>';
			} else {
				$terms_arr = explode ( "\n", $terms_plain_text );
				$slugs = array ();
				$add_terms = array ();
				$i = 1;
				foreach ( $terms_arr as $row ) {
					if ( preg_match ( '@^([^\t]*)\t(.+)@', $row, $result ) ) {
						if ( $name = trim ( $result[2] ) ) {
							$slug = trim ( $result[1] );
							$slug_org = $slug;
							if ( strpos ( $slug, '*' ) === 0 ) $slug = substr ( $slug, 1 );
							
							// Check duplicate
							if ( $slug && in_array ( $slug, $slugs ) )
								$this->error .= '<p>\'' . $slug . '\' ' . __( 'is a duplicate.', self::DOMAIN ) . '</p>';
							$slugs[] = $slug;
							
							if ( !$slug_org ) $slug_org = 'undefined_' . $i;
							$hierarchy = count ( explode ( "\t", rtrim ( $result[2] ) ) );
							$add_terms[$slug_org] = array ( 'hierarchy' => $hierarchy, 'name' => $name, 'term_order' => $i );
							$i++;
						}
					}
				}
				
				if ( !$add_terms )
					$this->error .= '<p>' . __( 'Format is not correct.', self::DOMAIN ) . '</p>';
			}
			
			if ( !$this->error ) {
				if ( count ( $add_terms ) > 0 ) {
					$current_hierarchy = 1;
					$history = array ();
					$prev_term_id = '';
					foreach ( $add_terms as $slug => $row ) {
						extract ( $row );
						if ( strpos ( $slug, 'undefined_' ) === 0 ) $slug = '';
						
						if ( strpos ( $slug, '*' ) === 0 ) {
							// delete
							$slug = substr ( $slug, 1 );
							if ( $term = get_term_by( 'slug', $slug, $this->taxonomy ) ) {
								$result = wp_delete_term( (int) $term->term_id, $this->taxonomy );
								
								if ( is_wp_error( $result ) )
									$this->error .= '<p>\'' . $slug . '\' ' . __( 'Failed to delete.', self::DOMAIN ) . $result->get_error_message() . '</p>';
							}
						} else {
							// change
							if ( strpos ( $slug, '>' ) !== false ) {
								list ( $slug, $slug_new ) =  explode ( '>', $slug );
								$slug = trim ( $slug );
								$slug_new = trim ( $slug_new );
								$args = array ( 'slug' => $slug_new );
							} else {
								$args = array ( 'slug' => $slug );
							}
							
							// parent
							$parent = 0;
							if ( $hierarchy > 1 ) {
								if ( $current_hierarchy < $hierarchy ) {
									// up
									$parent = $prev_term_id;
								} elseif ( $current_hierarchy > $hierarchy ) {
									// down
									$parent = $history[count ( $history ) - 2 - ( $current_hierarchy - $hierarchy )];
								} else {
									// stay
									$parent = $prev_term_parent;
								}
								
								// memorize the previous number
								$prev_term_parent = $parent;
							}
							$args['parent'] = $parent;
							
							if ( $term = get_term_by( 'slug', $slug, $this->taxonomy ) ) {
								// update
								$args['name'] = $name;
								$result = wp_update_term( (int) $term->term_id, $this->taxonomy, $args );
							} else {
								// insert
								$result = wp_insert_term( $name, $this->taxonomy, $args );
							}
							
							// result
							if ( is_wp_error( $result ) ) {
								$this->error .= '<p>\'' . $slug . '\' ' . __( 'Failed to register.', self::DOMAIN ) . $result->get_error_message() . '</p>';
							} else {
								$prev_term_id = (int) $result['term_id'];
								
								// if the first or hierarchy has changed, memorize the term ID.
								if ( $current_hierarchy != $hierarchy || !$history ) {
									$history[] = $prev_term_id;
								}
								
								// update term_order
								$wpdb->query( $wpdb->prepare( "
									UPDATE
										$wpdb->terms
									SET
										term_order = %d
									WHERE
										term_id = %d
								", $term_order, $prev_term_id ) );
							}
							
							// update the hierarchy
							$current_hierarchy = $hierarchy;
						}
					}
					
					if ( !$this->error ) {
						if ( $result )
							$this->updated = '<p>' . __( 'Updated.', self::DOMAIN ) . '</p>';
					}
				}
			}
		}
		
		$this->taxonomy = ( !empty ( $_POST['taxonomy'] ) ) ? esc_html( $_POST['taxonomy'] ) : '';
		add_action( 'admin_footer', array ( $this, 'bulk_term_editor_action_js' ) );
?>
<div class="wrap" id="bulk_term_editor">
<div id="icon-themes" class="icon32">&nbsp;</div><h2><?php echo __( 'Bulk Term Editor', self::DOMAIN ); ?></h2>
<?php
		if ( $this->error )
			echo '<div class="error">'  . $this->error . '</div>';
		if ( $this->updated )
			echo '<div class="updated">'  . $this->updated . '</div>';
?>
<p><?php echo __( 'You can register or edit terms in bulk. Copy cells in the spreadsheet, all that remains is to paste to this plugin.', self::DOMAIN ); ?></p>

<form method="post" action="?page=bulk_term_editor">
<table class="form-table">
<tr valign="top">
<th scope="row"><label for="fld_taxonomy"><?php echo __( 'Taxonomy', self::DOMAIN ); ?></label></th>
<td>
<select name="taxonomy" id="fld_taxonomy">
<option value=""></option>
<?php
$args = array (
	'public' => true
);
$taxonomies = get_taxonomies( $args, 'objects' );

foreach ( $taxonomies as $taxonomy_obj ) {
	$tax_name = esc_html( $taxonomy_obj->name );
	
	if ( $tax_name !== 'post_format' ) {
		echo '<option value="' . $tax_name . '"';
		if ( $this->taxonomy == $tax_name ) echo ' selected="selected"';
		echo '>' . esc_html( $taxonomy_obj->label ) . '(' . $tax_name . ')</option>' . "\n";
	}
}
?>
</select>
</td>
</tr>

<tr valign="top">
<th scope="row"><label for="fld_terms_plain_text"><?php echo __( 'Terms', self::DOMAIN ); ?></label></th>
<td>
<div id="bte_field"><textarea name="terms_plain_text" cols="100" rows="20" type="text" id="fld_terms_plain_text"><?php if ( $this->error && !empty ( $terms_plain_text ) ) echo $terms_plain_text; ?></textarea></div>
<p class="note"><a href="<?php echo plugins_url( 'img/format.png', __FILE__ ); ?>" target="_blank"><?php echo __( 'Format is ...', self::DOMAIN ); ?></a></p>
<ul class="note">
<li><?php echo __( 'Entering a \'*\' in beginning of line will delete.', self::DOMAIN ); ?></li>
<li><?php echo __( 'Entering a \'>New slug\' after the term slug will change the term slug.', self::DOMAIN ); ?></li>
<li><?php echo __( 'When the term slug is a blank or new, add.', self::DOMAIN ); ?></li>
</ul>
</td>
</tr>

<tr valign="top">
<th scope="row"><label for="fld_regex"><?php echo __( 'Replace characters', self::DOMAIN ); ?></label></th>
<td>
<p><label for="fld_regex"><?php echo __( 'Regular Expression', self::DOMAIN ); ?></label><br />
<input name="regex" size="80" type="text" id="fld_regex" /></p>
<p><label for="fld_characters"><?php echo __( 'Replacement characters', self::DOMAIN ); ?></label><br />
<input name="characters" size="80" type="text" id="fld_characters" /></p>
<p><input type="button" value="<?php echo __( 'Replace', self::DOMAIN ); ?>" class="button regex"></p>
</td>
</tr>
</table>
<p class="submit"><input type="submit" value="<?php echo __( 'Edit', self::DOMAIN ); ?>" class="button-primary">
<input type="button" value="<?php echo __( 'Revert to the original.', self::DOMAIN ); ?>" class="button return"></p>
<input type="hidden" name="mode" value="add" />
</form>
<!-- .wrap --></div>
<?php
	}
}
new Bulk_Term_Editor();
?>
