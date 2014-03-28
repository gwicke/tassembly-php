<?php namespace TAssembly;
use Exception;

/**
 * Template assembly language (tassembly) PHP runtime.
 *
 * @file
 * @ingroup Extensions
 * @copyright 2013; see AUTHORS.txt
 * @license The MIT License (MIT); see LICENSE.txt
 */

class TAssembly {
	/**
	 * Render an intermediate representation object into HTML
	 *
	 * @param string[] $ir
	 * @param string[] $model
	 * @param TAssemblyOptions $options
	 *
	 * @throws TAssemblyException if a subpart is not a 2-tuple, or if a control function is not known
	 *
	 * @return string HTML
	 */
	public static function render( array $ir, array $model = array(), TAssemblyOptions $options = null ) {
		$context = TAssemblyContext::createRootContextFromModel( $model, $options );
		return TAssembly::render_context( $ir, $context );
	}

	protected static function render_context( array $ir, TAssemblyContext $context ) {
		$bits = array();

		foreach( $ir as $bit ) {
			if ( is_string( $bit ) ) {
				$bits[] = $bit;
			} elseif ( is_array( $bit ) && count( $bit ) === 2 ) {
				// Control function
				list( $ctlFn, $ctlOpts ) = $bit;
				if ( $ctlFn === 'text' ) {
					$val = TAssembly::evaluate_expression( $ctlOpts, $context );
					if ( is_null( $val ) ) {
						$val = '';
					}
					$bits[] = htmlspecialchars( $val, ENT_XML1 );
				} elseif ( array_key_exists( $ctlFn, $context->f ) ) {
					$bits[] = $context->f[$ctlFn]( $ctlOpts, $context );
				} else {
					throw new TAssemblyException( "Function '$ctlFn' does not exist in the context.", $bit );
				}
			} else {
				throw new TAssemblyException( 'Template operation must be either string or 2-tuple (function, args)', $bit );
			}
		}

		return join('', $bits);
	}

	/**
	 * Evaluate a simple expression.
	 *
	 * Note: This uses php eval(); we are relying on the compiler to
	 * make sure nothing dangerous is passed in.
	 *
	 * @param $expr
	 * @param TAssemblyContext $context
	 * @return mixed|string
	 */
	protected static function evaluate_expression( $expr, TAssemblyContext $context ) {
		// Simple variable
		$matches = array();
		if ( preg_match( '/^(m|p(?:[cm]s?)?|rm|i|c)\.([a-zA-Z_$]+)$/', $expr, $matches ) ) {
			list( $x, $member, $key ) = $matches;
			return ( array_key_exists( $key, $context[$member] ) ? $context[$member][$key] : $expr );
		}

		// String literal
		if ( preg_match( '/^\'.*\'$/', $expr ) ) {
			return str_replace( '\\\'', '\'', substr( $expr, 1, -1 ) );
		}

		// Hopefully simple expression; which must be rewritten to use PHP style accessors


		// Otherwise just return the expression. At some point we may allow more
		// complicated things (like function calls... but not now).
		return $expr;
	}

	/**
	 * Rewrite a simple expression to be keyed on the context
	 *
	 * Allow objects { foo: 'basf' }
	 *
	 */
	protected static function rewriteExpression( $expr ) {
		$result = '';
		$i = -1;
		$c = '';

		do {
			if ( preg_match( '/^$|[\[:(]/', $c ) ) {
				// Match the empty string, or one of [, :, (
				$result .= $c;
				if (
					preg_match( '/[pri]/', $expr[$i+1] )
					&& preg_match( '/(?:p[cm]s?|rm|i)(?:[\.\)\]}]|$)/', substr( $expr, $i+1 ) )
				) {
					// This is an expression referencing the parent, root, or iteration scopes
					$result .= '$context->';
				}

			} elseif ( $c === "'") {
				// String literal, just skip over it and add it
				$match = array();
				preg_match( "/'(?:[^\\']+|\\')*'/", substr( $expr, $i ), $match );
				if ( !empty( $match ) ) {
					$result .= $match[0];
					$i += strlen( $match[0] );
				} else {
					throw new TAssemblyException( "Caught truncated string!", $expr );
				}

			} elseif ( $c === "{" ) {
				$result .= 'array(';

			} elseif ( $c === "}" ) {
				$result .= ')';
			}

			$i++;
			$c = $expr[$i];
		} while ( $c );
	}
}

class TAssemblyOptions {
	public $partials = array();
	public $functions = array();
}

class TAssemblyException extends \Exception {
	public function __construct($message = "", $ir = '', $code = 0, Exception $previous = null) {
		parent::__construct($message,$code,$previous); // TODO: Change the autogenerated stub
	}
}

class TAssemblyContext implements \ArrayAccess {
	/** @var TAssemblyContext Root context object */
	public $rc;

	/** @var string[] Root model array */
	public $rm;

	/** @var string[] Array of references to parent models, [0] is the immediate parent. */
	public $pms;

	/** @var string[] Reference to the parent model */
	public $pm;

	/** @var TAssemblyContext[] Array of references to parent contexts. [0] is the immediate parent. */
	public $pcs;

	/** @var TAssemblyContext Reference to the parent context object */
	public $pc;

	/** @var string[] Model for the current context (holds locals) */
	public $m;

	/** @var TAssemblyOptions Reference to the global object for function calls */
	public $g;

	/** @var array() Array of functions (accessible only from the root object) */
	public $f;

	/** @var ??? uhh... this is an iterator... not yet gotten there */
	public $i;

	public static function createRootContextFromModel( $model, TAssemblyOptions $options ) {
		$ctx = new TAssemblyContext();
		$ctx->rm = $model;
		$ctx->m = &$ctx->rm;
		$ctx->pms = array();
		$ctx->pcs = array();
		$ctx->g = $options;
		$ctx->f = &$ctx->g->functions;

		return $ctx;
	}

	public function offsetExists( $offset ) {
		return isset( $this->$offset );
	}

	public function offsetGet( $offset ) {
		return $this->$offset;
	}

	public function offsetSet( $offset, $value ) {
		if ( property_exists( $this, $offset ) ) {
			$this->$offset = $value;
		} else {
			throw new TAssemblyException( "Can not set property '$offset' on TAssemblyContext object" );
		}
	}

	public function offsetUnset( $offset ) {
		$this->$offset = null;
	}

	protected function __construct() {
		// Just making sure we can only use generators to construct this object
	}
}