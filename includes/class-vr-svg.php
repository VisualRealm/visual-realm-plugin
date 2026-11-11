<?php
/**
 * Allow-list SVG sanitizer for uploads made through the connector.
 *
 * @package VisualRealm
 */

defined( 'ABSPATH' ) || exit;

class VR_SVG {

	const ELEMENTS = array(
		'svg', 'g', 'defs', 'title', 'desc', 'symbol', 'use', 'path', 'rect', 'circle', 'ellipse', 'line',
		'polyline', 'polygon', 'text', 'tspan', 'textpath', 'lineargradient', 'radialgradient', 'stop',
		'clippath', 'mask', 'pattern', 'filter', 'fegaussianblur', 'feoffset', 'feblend', 'fecolormatrix',
		'fecomposite', 'feflood', 'femerge', 'femergenode', 'image', 'style', 'marker', 'metadata',
	);

	const ATTRIBUTES = array(
		'id', 'class', 'style', 'viewbox', 'width', 'height', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r',
		'rx', 'ry', 'd', 'points', 'fill', 'fill-opacity', 'fill-rule', 'clip-rule', 'stroke', 'stroke-width',
		'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset',
		'stroke-opacity', 'opacity', 'transform', 'offset', 'stop-color', 'stop-opacity', 'gradientunits',
		'gradienttransform', 'spreadmethod', 'fx', 'fy', 'clip-path', 'clippathunits', 'mask', 'maskunits',
		'patternunits', 'patterntransform', 'preserveaspectratio', 'xmlns', 'xmlns:xlink', 'version',
		'font-family', 'font-size', 'font-weight', 'letter-spacing', 'text-anchor', 'dominant-baseline',
		'href', 'xlink:href', 'filter', 'stddeviation', 'in', 'in2', 'result', 'mode', 'values', 'type', 'dx',
		'dy', 'flood-color', 'flood-opacity', 'operator', 'k1', 'k2', 'k3', 'k4', 'xml:space', 'display',
		'visibility', 'enable-background', 'markerwidth', 'markerheight', 'refx', 'refy', 'orient',
		'data-name', 'aria-label', 'aria-hidden', 'role', 'focusable',
	);

	/**
	 * Sanitize SVG markup.
	 *
	 * @param string $svg Raw SVG.
	 * @return string|WP_Error
	 */
	public static function sanitize( $svg ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error( 'no_dom', 'This server cannot check SVG files safely (PHP DOM extension missing).' );
		}
		$svg = (string) $svg;
		if ( preg_match( '/<!ENTITY/i', $svg ) ) {
			return new WP_Error( 'svg_entities', 'SVG files with entity declarations are not allowed.' );
		}
		$svg = preg_replace( '/<!DOCTYPE[^>\[]*(\[[^\]]*\])?\s*>/i', '', $svg );
		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$ok   = $dom->loadXML( $svg, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok || ! $dom->documentElement || 'svg' !== strtolower( $dom->documentElement->localName ) ) {
			return new WP_Error( 'svg_invalid', 'That file is not a valid SVG.' );
		}
		self::clean( $dom->documentElement );
		// Drop comments and processing instructions (e.g. editor generator notes).
		$xpath = new DOMXPath( $dom );
		foreach ( iterator_to_array( $xpath->query( '//comment() | //processing-instruction()' ) ) as $node ) {
			$node->parentNode->removeChild( $node );
		}
		$out = $dom->saveXML( $dom->documentElement );
		return false === $out ? new WP_Error( 'svg_invalid', 'Could not write the cleaned SVG.' ) : $out;
	}

	/**
	 * Recursively remove disallowed elements and attributes.
	 *
	 * @param DOMElement $el Element.
	 */
	protected static function clean( DOMElement $el ) {
		// Unwrap links and switches (keeping their artwork) until none are left,
		// so anything they contained is checked below like any other child.
		do {
			$unwrapped = false;
			foreach ( iterator_to_array( $el->childNodes ) as $child ) {
				if ( $child instanceof DOMElement && in_array( strtolower( $child->localName ), array( 'a', 'switch' ), true ) ) {
					foreach ( iterator_to_array( $child->childNodes ) as $grand ) {
						$el->insertBefore( $grand, $child );
					}
					$el->removeChild( $child );
					$unwrapped = true;
				}
			}
		} while ( $unwrapped );
		foreach ( iterator_to_array( $el->childNodes ) as $child ) {
			if ( $child instanceof DOMElement && ! in_array( strtolower( $child->localName ), self::ELEMENTS, true ) ) {
				$el->removeChild( $child );
			}
		}
		foreach ( iterator_to_array( $el->childNodes ) as $child ) {
			if ( $child instanceof DOMElement ) {
				self::clean( $child );
			}
		}
		foreach ( iterator_to_array( $el->attributes ) as $attr ) {
			$name  = strtolower( $attr->nodeName );
			$value = (string) $attr->nodeValue;
			$bad   = ! in_array( $name, self::ATTRIBUTES, true ) || 0 === strpos( $name, 'on' );
			if ( ! $bad && in_array( $name, array( 'href', 'xlink:href' ), true ) ) {
				// Only in-document references or embedded raster images.
				$bad = ! ( 0 === strpos( $value, '#' ) || preg_match( '#^data:image/(png|jpe?g|gif|webp);base64,#i', $value ) );
			}
			// Backslashes allow CSS escapes (u\72l, @\69mport) that hide external loads.
			if ( ! $bad && ( false !== strpos( $value, '\\' ) || preg_match( '/(javascript|vbscript|expression\s*\(|url\s*\(\s*[\'"]?\s*(?!#)|@import)/i', $value ) ) ) {
				$bad = true;
			}
			if ( $bad ) {
				$el->removeAttributeNode( $attr );
			}
		}
		if ( 'style' === strtolower( $el->localName ) && ( false !== strpos( $el->textContent, '\\' ) || preg_match( '/(@import|javascript|expression\s*\(|url\s*\(\s*[\'"]?\s*(?!#))/i', $el->textContent ) ) ) {
			$el->parentNode->removeChild( $el );
		}
	}
}
