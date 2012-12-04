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

		add_action("{$this->target_taxonomy}_add_form_fields", array( $this, 'taxonomy_add_form_fields' ) );  // form for adding terms  /wp-admin/edit-tags.php?taxonomy=taxonomy
		add_action("{$this->target_taxonomy}_edit_form_fields", array( $this, 'taxonomy_edit_form_fields' ) ); // form for editing terms  /wp-admin/edit-tags.php?action=edit&taxonomy=taxonomy&tag_ID=12345&post_type=post
		add_action("create_term", array( $this, 'create_term' ), 10, 3 ); // happens after form is submitted
		add_action("edit_term", array( $this, 'edit_term' ), 10, 3 ); // happens after form is submitted
		add_filter("get_{$this->target_taxonomy}", array( $this, 'get_taxonomy' ), 10, 2 ); // when a single term is requested
	}

	function init() {
		$args = array(
			'label' => "{$this->target_taxonomy}_related",
			'hierarchical' => false, // not sure on this yet :)
			'rewrite' => false,
			'public' => false,
			'show_ui' => false,
			'show_in_nav_menus' => false,
			'show_tagcloud' => false
		);

		register_taxonomy( $args['label'], 'post', $args ); //attaching to post so that it will exist but over-riding that behavior later
	}

	/*
	 * Find every instance where our target taxonomy shows up in a query and make sure we are
	 * searching for the correct terms by converting incorrect forms to their correct synonym.
	 */
	function pre_get_posts() {
		
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
			$correctform = get_term_by( 'name', sanitize_text_field( $_POST['correctform'] ), $this->target_taxonomy . $this->related_suffix );
			if ( isset( $correctform->term_id ) )
				$args['parent'] = $correctform->term_id;
		}

		// now get the shadow term and point it to it's correct form
		if ( $shadow_term = $this->get_shadow_term( $term_id ) )
			wp_update_term( $shadow_term->term_id, $this->target_taxonomy . $this->related_suffix, $args );
		else
			return;
var_dump( get_term( $shadow_term ) );
		// now set up related terms if provided
		if ( 'related' == $_POST['relativetype'] && isset( $_POST['relatedterms'] ) ) {
			$relatedterms = explode( ',', $_POST['relatedterms'] );
			$relatedterms = array_walk( $relatedterms, array( $this, 'get_shadow_term' ) );
			wp_set_object_terms( $related_shadow->term_id, $relatedterms, $this->target_taxonomy );
var_dump( wp_get_object_terms( $related_shadow->term_id, $this->target_taxonomy ) );
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
				/* if we are passed a string there is a chance that the term just doesn't exist yet
				 * wp_insert_term only inserts the term if it doesn't exist
				 */
				wp_insert_term( sanitize_text_field( $term ), $this->target_taxonomy );
				$term = get_term_by( 'name', $term, $this->target_taxonomy );
			endif;

			// if still nothing give up
			if ( ! isset( $term->name ) )
				return false;
		}

		// will only create term if it doesn't already exist
		wp_insert_term( $term->name, $this->target_taxonomy . $this->related_suffix );

		// finally get the shadow term
		$shadow_term = get_term_by( 'name', $term->name, $this->target_taxonomy . $this->related_suffix );

		// return our match or false if nothing found
		return ( is_wp_error( $shadow_term ) || 0 == $shadow_term ) ? false : $shadow_term;
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