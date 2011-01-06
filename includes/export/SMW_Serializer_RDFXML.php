<?php

/**
 * File holding the SMWRDFXMLSerializer class that provides basic functions for
 * serialising OWL data in RDF/XML syntax. 
 *
 * @file SMW_Serializer.php
 * @ingroup SMW
 *
 * @author Markus Krötzsch
 */

/**
 * Class for serializing exported data (encoded as SMWExpData object) in
 * RDF/XML. 
 *
 * @ingroup SMW
 */
class SMWRDFXMLSerializer extends SMWSerializer{
	/**
	 * True if the $pre_ns_buffer contains the beginning of a namespace
	 * declaration block to which further declarations for the current
	 * context can be appended. 
	 */
	protected $namespace_block_started;
	/**
	 * True if the namespaces that are added at the current serialization stage
	 * become global, i.e. remain available for all later contexts. This is the
	 * case in RDF/XML only as long as the header has not been streamed to the
	 * client (reflected herein by calling flushContent()). Later, namespaces
	 * can only be added locally to individual elements, thus requiring them to
	 * be re-added multiple times if used in many elements.
	 */
	protected $namespaces_are_global;

	public function clear() {
		parent::clear();
		$this->namespaces_are_global = false;
		$this->namespace_block_started = false;
	}

	protected function serializeHeader() {
		$this->namespaces_are_global = true;
		$this->namespace_block_started = true;
		$this->pre_ns_buffer =
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
			"<!DOCTYPE rdf:RDF[\n" .
			"\t<!ENTITY rdf " . $this->makeValueEntityString( SMWExporter::expandURI( '&rdf;' ) ) . ">\n" .
			"\t<!ENTITY rdfs " . $this->makeValueEntityString( SMWExporter::expandURI( '&rdfs;' ) ) . ">\n" .
			"\t<!ENTITY owl " . $this->makeValueEntityString( SMWExporter::expandURI( '&owl;' ) ) . ">\n" .
			"\t<!ENTITY swivt " . $this->makeValueEntityString( SMWExporter::expandURI( '&swivt;' ) ) . ">\n" .
			// A note on "wiki": this namespace is crucial as a fallback when it would be illegal to start e.g. with a number.
			// In this case, one can always use wiki:... followed by "_" and possibly some namespace, since _ is legal as a first character.
			"\t<!ENTITY wiki "  . $this->makeValueEntityString( SMWExporter::expandURI( '&wiki;' ) ) . ">\n" .
			"\t<!ENTITY property " . $this->makeValueEntityString( SMWExporter::expandURI( '&property;' ) ) . ">\n" .
			"\t<!ENTITY wikiurl " . $this->makeValueEntityString( SMWExporter::expandURI( '&wikiurl;' ) ) . ">\n" .
			"]>\n\n" .
			"<rdf:RDF\n" .
			"\txmlns:rdf=\"&rdf;\"\n" .
			"\txmlns:rdfs=\"&rdfs;\"\n" .
			"\txmlns:owl =\"&owl;\"\n" .
			"\txmlns:swivt=\"&swivt;\"\n" .
			"\txmlns:wiki=\"&wiki;\"\n" .
			"\txmlns:property=\"&property;\"";
		$this->global_namespaces = array( 'rdf' => true, 'rdfs' => true, 'owl' => true, 'swivt' => true, 'wiki' => true, 'property' => true );
		$this->post_ns_buffer .= ">\n\n";
	}

	protected function serializeFooter() {
		$this->post_ns_buffer .= "\t<!-- Created by Semantic MediaWiki, http://semantic-mediawiki.org/ -->\n";
		$this->post_ns_buffer .= '</rdf:RDF>';
	}
	
	public function serializeDeclaration( $uri, $typename ) {
		$this->post_ns_buffer .= "\t<$typename rdf:about=\"$uri\" />\n";
	}

	public function serializeExpData( SMWExpData $data ) {
		$this->serializeNestedExpData( $data, '' );
		$this->serializeNamespaces();
		if ( !$this->namespaces_are_global ) {
			$this->pre_ns_buffer .= $this->post_ns_buffer;
			$this->post_ns_buffer = '';
			$this->namespace_block_started = false;
		}
	}

	public function flushContent() {
		$result = parent::flushContent();
		$this->namespaces_are_global = false; // must not be done before calling the parent method (which may declare namespaces)
		$this->namespace_block_started = false;
		return $result;
	}
	
	protected function serializeNamespace( $shortname, $uri ) {
		if ( $this->namespaces_are_global ) {
			$this->global_namespaces[$shortname] = true;
			$this->pre_ns_buffer .= "\n\t";
		} else {
			$this->pre_ns_buffer .= ' ';
		}
		$this->pre_ns_buffer .= "xmlns:$shortname=\"$uri\"";
	}

	/**
	 * Serialize the given SMWExpData object, possibly recursively with
	 * increased indentation.
	 *
	 * @param $data SMWExpData containing the data to be serialised.
	 * @param $indent string specifying a prefix for indentation (usually a sequence of tabs)
	 */
	protected function serializeNestedExpData( SMWExpData $data, $indent ) {
		$this->recordDeclarationTypes( $data );

		$type = $data->extractMainType()->getQName();
		if ( !$this->namespace_block_started ) { // start new ns block
			$this->pre_ns_buffer .= "\t$indent<$type";
			$this->namespace_block_started = true;
		} else { // continue running block
			$this->post_ns_buffer .= "\t$indent<$type";
		}

		if ( ( $data->getSubject() instanceof SMWExpLiteral ) ||
		     ( $data->getSubject() instanceof SMWExpResource ) ) {
			 $this->post_ns_buffer .= ' rdf:about="' . $data->getSubject()->getName() . '"';
		} // else: blank node, no "rdf:about"

		if ( count( $data->getProperties() ) == 0 ) { // nothing else to export
			$this->post_ns_buffer .= " />\n";
		} else { // process data
			$this->post_ns_buffer .= ">\n";

			foreach ( $data->getProperties() as $property ) {
				$prop_decl_queued = false;
				$class_type_prop = $this->isOWLClassTypeProperty( $property );

				foreach ( $data->getValues( $property ) as $value ) {
					$this->post_ns_buffer .= "\t\t$indent<" . $property->getQName();
					$this->requireNamespace( $property->getNamespaceID(), $property->getNamespace() );
					$object = $value->getSubject();

					if ( $object instanceof SMWExpLiteral ) {
						$prop_decl_type = SMW_SERIALIZER_DECL_APROP;
						if ( $object->getDatatype() != '' ) {
							$this->post_ns_buffer .= ' rdf:datatype="' . $object->getDatatype() . '"';
						}
						$this->post_ns_buffer .= '>' .
							str_replace( array( '&', '>', '<' ), array( '&amp;', '&gt;', '&lt;' ), $object->getName() ) .
							'</' . $property->getQName() . ">\n";
					} else { // resource (maybe blank node), could have subdescriptions
						$prop_decl_type = SMW_SERIALIZER_DECL_OPROP;
						$collection = $value->getCollection();
						if ( $collection !== false ) { // RDF-style collection (list)
							$this->post_ns_buffer .= " rdf:parseType=\"Collection\">\n";
							foreach ( $collection as $subvalue ) {
								$this->serializeNestedExpData( $subvalue, $indent . "\t\t" );
								if ( $class_type_prop ) {
									$this->requireDeclaration( $subvalue, SMW_SERIALIZER_DECL_CLASS );
								}
							}
							$this->post_ns_buffer .= "\t\t$indent</" . $property->getQName() . ">\n";
						} else {
							if ( $class_type_prop ) {
								$this->requireDeclaration( $object, SMW_SERIALIZER_DECL_CLASS );
							}
							if ( count( $value->getProperties() ) > 0 ) { // resource with data: serialise
								$this->post_ns_buffer .= ">\n";
								$this->serializeNestedExpData( $value, $indent . "\t\t" );
								$this->post_ns_buffer .= "\t\t$indent</" . $property->getQName() . ">\n";
							} else { // resource without data
								if ( !$object->isBlankNode() ) {
									$this->post_ns_buffer .= ' rdf:resource="' . $object->getName() . '"';
								}
								$this->post_ns_buffer .= "/>\n";
							}
						}
					}

					if ( !$prop_decl_queued ) {
						$this->requireDeclaration( $property, $prop_decl_type );
						$prop_decl_queued = true;
					}
				}
			}
			$this->post_ns_buffer .= "\t$indent</" . $type . ">\n";
		}
	}
	
	/**
	 * Escape a string in the special form that is required for values in 
	 * DTD entity declarations in XML. Namely, this require the percent sign
	 * to be replaced.
	 * @param string $string to be escaped 
	 */
	protected function makeValueEntityString( $string ) {
		return "'" . str_replace( '%','&#37;',$string ) . "'";
	}

}
