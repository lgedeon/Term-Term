<?php

/*
 * Instantiate class with taxonomy you want to create relations for. One instance of class per taxonomy to relate with.
 * Register new taxonomy named $this->taxonomy.'_related'
 * Intercept:
 *   get terms on a post
 *     actually, we don't interfere at this point, fix old via cli and use autocomplete to keep new good
 ~   queries that use the target taxonomy (pre_get_posts, wp_query, get_post)
 ~     we do want to intercept and switch to correct form here to help with old links, but may need a 301
 *   auto-complete for term entry (catch new "category" box as well)
 *     suggest related terms after each term has been added (zemanta style)
 *     make sure that we are using correct form after submit
 -   tag creation page - replace parent selector with a radio option to specify whether this is
 -     an incorrect spelling of: correct term
 -     a child of: multiple terms
 *   give auto complete a list of matching terms and their coresponding correct forms
 * 
 *  get_related_terms() -
 *     in TC's topic time-line
 *     allow selecting posts only in self, in n-parent, or n-cousin
 * 
 */

/*
tag creation - luke
auto_complete - jon
get_related_terms with pre_get_posts - eric
*/

class Term_Term_Relationship {
	
	var $target_taxonomy = '',
		$related_suffix = '_related';
	
	// Check for live_cache_check on init. If set return cached value
	public function __construct( $taxonomy ) {
		$this->target_taxonomy = $taxonomy;

		add_action( 'init',                                      array( $this, 'init' ) );
		add_action( "{$this->target_taxonomy}_add_form_fields",  array( $this, 'taxonomy_add_form_fields' ) );  // form for adding terms  /wp-admin/edit-tags.php?taxonomy=taxonomy
		add_action( "{$this->target_taxonomy}_edit_form_fields", array( $this, 'taxonomy_edit_form_fields' ) ); // form for editing terms  /wp-admin/edit-tags.php?action=edit&taxonomy=taxonomy&tag_ID=12345&post_type=post
		add_action( "create_term",                               array( $this, 'create_term' ), 10, 3 );        // happens after form is submitted
		add_action( "edit_term",                                 array( $this, 'edit_term' ), 10, 3 );          // happens after form is submitted

		add_filter( "get_{$this->target_taxonomy}", array( $this, 'get_taxonomy' ), 10, 2 ); // when a single term is requested
		add_filter( 'pre_get_posts',                array( $this, 'pre_get_posts' ) );       // Filter tax queries to return canonical tags
	}

	function init() {
		$args = array(
			'label'             => "{$this->target_taxonomy}{$this->related_suffix}",
			'hierarchical'      => true, // This is required to do queries based on parent/child relationships.
			'rewrite'           => false,
			'public'            => false,
			'show_ui'           => false,
			'show_in_nav_menus' => false,
			'show_tagcloud'     => false
		);

		register_taxonomy( $args['label'], 'post', $args ); //attaching to post so that it will exist but over-riding that behavior later
	}

	/**
	 * Find every instance where our target taxonomy shows up in a query and make sure we are
	 * searching for the correct terms by converting incorrect forms to their correct synonym.
	 *
	 * @param WP_Query $query
	 *
	 * @return WP_Query
	 */
	function pre_get_posts( &$query ) {
		$process = false;
		if ( 'category' == $this->target_taxonomy && $query->is_category() ) {
			$category = $query->get_queried_object();

			$query->set( 'category_name', '' );
			$query->set( 'cat', '' );
			$query->set( 'category__in', $this->get_term_family( $category->term_id ) );
		} else if ( 'post_tag' == $this->target_taxonomy && $query->is_tag() ) {
			$tag = $query->get_queried_object();

			$query->set( 'tag', '' );
			$query->set( 'tag_id', '' );
			$query->set( 'tag__in', $this->get_term_family( $tag->term_id ) );
		} else if ( $query->is_tax( $this->target_taxonomy ) ) {

		}

		return $query;
	}

	/**
	 * Get an array of terms related to the specified term.
	 *
	 * This function will walk up the relationship tree until it finds a parent term (a term where the term_id is the
	 * same as its parent's term_id).  It will then gather all children of that parent term  and return an array of IDs
	 * for the entire family.
	 *
	 * Example, if your structure is:
	 * ---------------------------------
	 * | term_id | term_name | parent  |
	 * ---------------------------------
	 * |    5    |   Apple   |    5    |
	 * |   12    |   apple   |    5    |
	 * |   23    | apple-inc |    5    |
	 * ---------------------------------
	 *
	 * get_term_family( 5 ) => array( 5, 12, 23 )
	 * get_term_family( 12 ) => array( 5, 12, 23 )
	 * get_term_family( 23 ) => array( 5, 12, 23 )
	 *
	 * @param int $term_id ID of the term for which to parse and return a family.
	 *
	 * @return array
	 */
	function get_term_family( $term_id ) {
		$terms = array();

		// Get the original term
		$term = get_term( $term_id, $this->target_taxonomy );

		// Get its relationships
		$related = get_term( $term->term_id, $this->target_taxonomy . $this->related_suffix );

		// If the term is not a parent, use its parent to get related terms
		if ( $term->term_id != $related->parent ) {
			return $this->get_term_family( $related->parent );
		}

		// We know the term is a parent, so include it in the final array.
		$terms[] = $term_id;

		// Get all children of that term
		$children = get_terms(
			$this->target_taxonomy . $this->related_suffix,
			array(
			     'parent'     => '',
			     'child_of'   => intval( $term_id ),
			     'hide_empty' => false,
			     'fields'     => 'ids'
			)
		);

		$terms = array_merge( $terms, $children );

		return $terms;
	}

	/*
	 * Add radio option for selecting whether this is an incorrect form of another term or a
	 * child term. Then add field for entering correct form or list of parents.
	 * Seperate functions for add vs. edit, since layout is significatly different.
	 * 
	 * todo: add JS to hide old parent selector and either correctform or relatedterms
	 */
	function taxonomy_add_form_fields() {
		?>
		<div class="form-field">
			Related Terms
			<p>
				<label for="synonym"><input type="radio" name="relativetype" id="synonym" value="synonym" style="width: 20px;">This is a variant, non-preferred spelling of a different term.</label>
				<label for="related"><input type="radio" name="relativetype" id="related" value="related" style="width: 20px;">This is the preferred spelling/form for this term.</label>
			</p>
		</div>
		<div class="form-field">
			<label for="correctform">Correct form/spelling of term</label>
			<input name="correctform" id="correctform" type="text" value="" size="40" />
			<p class="description">Enter correct spelling, capitalization, punctuation, etc. for this term. Then if someone uses the value in the name field above as a <?php echo $this->target_taxonomy; ?>, it will be corrected to this value.</p>
		</div>
		<div class="form-field">
			<label for="relatedterms">Parent terms</label>
			<input name="relatedterms" id="relatedterms" type="text" value="" size="80" />
			<p class="description">Enter a comma separated list of terms that could be considered parents of this term. You might have a Big Band <?php echo $this->target_taxonomy; ?> that is a child of the Jazz, Instrumental, and Swing <?php echo $this->target_taxonomy; //todo get plural form ?>s.</p>
		</div>
		<?php
	}
	function taxonomy_edit_form_fields() {
		?>
		<tr class="form-field">
			<th scope="row" valign="top">Related Terms</th>
			<td>
				<label for="synonym"><input type="radio" name="relativetype" id="synonym" value="synonym" style="width: 20px;">This is a variant, non-preferred spelling of a different term.</label><br>
				<label for="related"><input type="radio" name="relativetype" id="related" value="related" style="width: 20px;">This is the preferred spelling/form for this term.</label>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row" valign="top"><label for="correctform">Correct form/spelling of term</label></th>
			<td><input name="correctform" id="correctform" type="text" value="" size="40" />
			<p class="description">Enter correct spelling, capitalization, punctuation, etc. for this term. Then if someone uses the value in the name field above as a <?php echo $this->target_taxonomy; ?>, it will be corrected to this value.</p></td>
		</tr>
		<tr class="form-field">
			<th scope="row" valign="top"><label for="relatedterms">Parent terms</label></th>
			<td><input name="relatedterms" id="relatedterms" type="text" value="" size="80" />
			<p class="description">Enter a comma separated list of terms that could be considered parents of this term. You might have a Big Band <?php echo $this->target_taxonomy; ?> that is a child of the Jazz, Instrumental, and Swing <?php echo $this->target_taxonomy; //todo get plural form ?>s.</p></td>
		</tr>
		<?php
	}

	/*
	 * Gets called any time a term is created. Make sure we are working on the correct $taxonomy.
	 * The values for the fields added by this plugin *may* be in $_POST, but terms can be
	 * added, and this action called, in several places we don't touch - for example post
	 * imports or xmlrpc.
	 * replace parent selector (if it exists) with a radio option to specify whether this is
	 *  an incorrect spelling of: correct term
	 *  a child of: multiple terms
	 * May seperate functionality for hier vs non-hier. Hierarchical may not need multi-parent.
	 */
	function create_term( $term_id, $tt_id, $taxonomy ) {

	}

	/*
	 * Same as create_term, just occuring on editing an existing term. Not sure yet if these
	 * need to be seperate.
	 * 
	 * term - the term in the real world (target_tax) that we are editing 
	 * shadow_term - the matching term in our _related tax - parent is term if this is a
	 * correct form or an alternate term from target-tax if not. Related terms are defined
	 * as this shadow pointing to shadows of other terms.
	 * 
	 * Note: WP up to 3.5 allows parents to be in a different taxonomy. If this ever changes
	 * our implementation may need to change as well.
	 */
	function edit_term( $term_id, $tt_id, $taxonomy ) {
		if ( $taxonomy != $this->target_taxonomy || ! isset( $_POST['relativetype'] ) )
			return;

		// default parent for the shadow_term we are creating is the same term but in the target taxonomy
		$args['parent'] = $term_id;

		// unless we over-ride that with the correctform field
		if ( 'synonym' == $_POST['relativetype'] && isset( $_POST['correctform'] ) ) {
			// Reason for pointing back to target tax is that this is never more than one layer deep and makes querying faster.
			$correctform = get_term_by( 'name', sanitize_text_field( $_POST['correctform'] ), $this->target_taxonomy );
			if ( isset( $correctform->term_id ) )
				$args['parent'] = $correctform->term_id;
		}

		// now get the shadow term and point it to it's correct form
		if ( $shadow_term = $this->get_shadow_term( $term_id ) )
			wp_update_term( $shadow_term->term_id, $this->target_taxonomy . $this->related_suffix, $args );
		else
			return;

		// now set up related terms if provided
		if ( 'related' == $_POST['relativetype'] && isset( $_POST['relatedterms'] ) ) {
			$relatedterms = explode( ',', $_POST['relatedterms'] );
			//$relatedterms = array_walk( $relatedterms, array( $this, 'get_shadow_term' ) );
			wp_set_object_terms( $shadow_term->term_id, $relatedterms, $this->target_taxonomy . $this->related_suffix );
var_dump( wp_get_object_terms( $shadow_term->term_id, $this->target_taxonomy . $this->related_suffix ) );
		}

		//apps, ecommerce, Education
	}

	/*
	 * Get the shadow of a term. Create it if it does not exist.
	 */
	function get_shadow_term( $term ) {

		// if we did not recieve a term object let's try to get one
		if ( ! isset( $term->name ) ) {

			// if we are passed an id or term name let's get the term object
			if ( is_int( $term ) ) :
				$term = get_term( $term, $this->target_taxonomy );
			elseif ( is_string( $term ) ) :
				$_term = get_term_by( 'name', sanitize_text_field( $term ), $this->target_taxonomy );
				if ( isset( $_term->name ) ) :
					$term = $_term;
				else :
					$_term = wp_insert_term( sanitize_text_field( $term ), $this->target_taxonomy );
					if ( isset ( $_term['term_id'] ) )
						$term = get_term( $_term['term_id'], $this->target_taxonomy);
				endif;
			endif;

			// if still nothing give up
			if ( ! isset( $term->name ) )
				return false;
		}

		// see if the shadow already exists
		$shadow_term = get_term_by( 'name', $term->name, $this->target_taxonomy . $this->related_suffix );

		// if not let's try to insert the term and set shadow to it
		if ( ! isset( $shadow_term->name ) ) {
			$_term = wp_insert_term( sanitize_text_field( $term->name ), $this->target_taxonomy . $this->related_suffix );
			if ( isset ( $_term['term_id'] ) )
				$shadow_term = get_term( $_term['term_id'], $this->target_taxonomy . $this->related_suffix );
		}
		
		// last chance if we failed to create even a shadow, we die a horrible death
		if ( ! isset( $shadow_term->name ) )
			return false;
		
		// looks like we made it - let's stand tall and cast that shadow
		return $shadow_term;
	}

	/*
	 * When a term in our target taxonomy is requested and it happens to be an incorrect form,
	 * let's return the correct form.
	 */
	function get_taxonomy( $term, $taxonomy ) {
		return $term;
	}

	/*
	 * Accepts one or an array of terms as integers or term objects.
	 * Returns array of terms (ids or objects) with a closeness score and count of posts with
	 * that term.
	 * If an array of terms is given then we can return the related terms for each or
	 * consolidate them all together.
	 * Children are the closest relatives, direct parents are next followed by children
	 * common parents - the more common parents the closer the score, next more distant
	 * ancestors. Anything out side that considered unrelated.
	 */
	public function get_related_terms ( $terms, $consolidate = false ) {
		
		
		return $family_trees;
	}
}

$tttt = new Term_Term_Relationship('category');
