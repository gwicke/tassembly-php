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
	public static function render( array &$ir, array &$model = array(), TAssemblyOptions &$options = null ) {
		if ( $options == null ) {
			$options = new TAssemblyOptions();
		}
		$context = self::createRootContext( $model, $options );
		return TAssembly::render_context( $ir, $context );
	}


	protected static function render_context( array &$ir, Array &$context ) {
		$bits = array();
		static $builtins = Array (
			'foreach' => true,
			'attr' => true,
			'if' => true,
			'ifnot' => true,
			'with' => true,
			'template' => true,
		);

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
					if ( defined( 'ENT_XML1' ) ) {
						$bits[] = htmlspecialchars( $val, ENT_XML1 );
					} else {
						$bits[] = htmlspecialchars( $val, ENT_NOQUOTES );
					}
				} elseif ( isset($builtins[$ctlFn]) ) {
					$ctlFn = 'ctlFn_' . $ctlFn;
					$bits[] = self::$ctlFn( $ctlOpts, $context );
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
	 * Evaluate an expression in the given context
	 *
	 * Note: This uses php eval(); we are relying on the compiler to
	 * make sure nothing dangerous is passed in.
	 *
	 * @param $expr
	 * @param Array $context
	 * @return mixed|string
	 */
	protected static function evaluate_expression( &$expr, Array &$context ) {
		// Simple variable
		if ( preg_match( '/m\.([a-zA-Z_$]+)$/', $expr, $matches) ) {
			return $context['m'][$matches[1]];
		}

		// String literal
		if ( preg_match( '/^\'.*\'$/', $expr ) ) {
			return str_replace( '\\\'', '\'', substr( $expr, 1, -1 ) );
		}

		// More complex var
		if ( preg_match( '/^(m|p(?:[cm]s?)?|rm|i|c)(?:\.([a-zA-Z_$]+))?$/', $expr, $matches ) ) {
			list( $x, $member ) = $matches;
			$key = count($matches) == 3 ? $matches[2] : false;
			if ( $key && is_array( $context[$member] ) ) {
				return ( array_key_exists( $key, $context[$member] ) ?
					$context[$member][$key] : '' );
			} else {
				$res = $context[$member];
				return $res ? $res : '';
			}
		}

		// More complex expression which must be rewritten to use PHP style accessors
		$newExpr = self::rewriteExpression( $expr );
		//echo $newExpr . "\n";
		$model = $context['m'];
		return eval('return ' . $newExpr . ';');
	}

	/**
	 * Rewrite a simple expression to be keyed on the context
	 *
	 * Allow objects { foo: 'basf', bar: contextVar.arr[5] }
	 *
	 * TODO: error checking for member access
	 */
	protected static function rewriteExpression( &$expr ) {
		$result = '';
		$i = -1;
		$c = '';
		$len = strlen( $expr );
		$inArray = false;

		do {
			if ( preg_match( '/^$|[\[:(]/', $c ) ) {
				// Match the empty string (start of expression), or one of [, :, (
				if ( $inArray ) {
					// close the array reference
					$result .= "']";
					$inArray = false;
				}
				if ($c != ':') {
					$result .= $c;
				}
				$remainingExpr = substr( $expr, $i+1 );
				if ( preg_match( '/[pri]/', $expr[$i+1] )
					&& preg_match( '/(?:p[cm]s?|r[cm]|i)(?:[\.\)\]}]|$)/', $remainingExpr ) )
				{
					// This is an expression referencing the parent, root, or iteration scopes
					$result .= "\$context['";
					$inArray = true;
				} else if ( preg_match( '/^m(\.)?/', $remainingExpr, $matches ) ) {
					if (count($matches) > 1) {
						$result .= "\$model['";
						$i += 2;
						$inArray = true;
					} else {
						$result .= '$model';
						$i++;
					}
				} else if ( $c == ':' ) {
					$result .= '=>';
				} else if ( preg_match('/^([a-zA-Z_$][a-zA-Z0-9_$]*):/',
								$remainingExpr, $match) )
				{
					// unquoted object key
					$result .= "'" . $match[1] . "'";
					$i += strlen($match[1]) + 2;
				}


			} elseif ( $c === "'") {
				// String literal, just skip over it and add it
				$match = array();
				preg_match( '/^(?:[^\\\']+|\\\')*\'/', substr( $expr, $i + 1 ), $match );
				if ( !empty( $match ) ) {
					$result .= $c . $match[0];
					$i += strlen( $match[0] );
				} else {
					throw new TAssemblyException( "Caught truncated string!" . $expr );
				}
			} elseif ( $c === "{" ) {
				// Object
				$result .= 'Array(';

				if ( preg_match('/^([a-zA-Z_$][a-zA-Z0-9_$]*):/',
					substr( $expr, $i+1 ), $match) )
				{
					// unquoted object key
					$result .= "'" . $match[1] . "'";
					$i += strlen($match[1]);
				}

			} elseif ( $c === "}" ) {
				// End of object
				$result .= ')';
			} elseif ( $c === "." ) {
				if ( $inArray ) {
					$result .= "']['";
				} else {
					$inArray = true;
					$result .= "['";
				}
			} else {
				// Anything else is sane as it conforms to the quite
				// restricted TAssembly spec, just pass it through
				$result .= $c;
			}

			$i++;
		} while ( $i < $len && $c = $expr[$i] );
		if ($inArray) {
			// close an open array reference
			$result .= "']";
		}
		return $result;
	}

	public static function createRootContext( &$model, TAssemblyOptions &$options ) {
		$ctx = Array (
			'rm' => $model,
			'm' => $model,
			'pm' => null,
			'pms' => array(),
			'pcs' => array(),
			'g' => $options->globals,
			'options' => $options
		);
		$ctx['rc'] = &$ctx;

		return $ctx;
	}

	protected static function createChildCtx ( &$parCtx, &$model ) {
		$ctx = Array(
			'm' => $model,
			'pc' => $parCtx,
			'pm' => $parCtx['m'],
			'pms' => array_merge(Array($model), $parCtx['pms']),
			'rm' => $parCtx['rm'],
			'rc' => $parCtx['rc'],
		);
		$ctx['pcs'] = array_merge(Array($ctx), $parCtx['pcs']);
		return $ctx;
	}

	protected static function getTemplate(&$tpl, &$ctx) {
		if (is_array($tpl)) {
			return $tpl;
		} else {
			// String literal: strip quotes
			$tpl = preg_replace('/^\'(.*)\'$/', '$1', $tpl);
			return $ctx['rc']['options']->partials[$tpl];
		}
	}

	protected static function ctlFn_foreach (&$opts, &$ctx) {
		$iterable = self::evaluate_expression($opts['data'], $ctx);
		if (!is_array($iterable)) {
			return '';
		}
		$bits = array();
		$newCtx = self::createChildCtx($ctx, null);
		$len = count($iterable);
		for ($i = 0; $i < $len; $i++) {
			$newCtx['m'] = $iterable[$i];
			$newCtx['pms'][0] = $iterable[$i];
			$newCtx['i'] = $i;
			$bits[] = self::render_context($opts['tpl'], $newCtx);
		}
		return join('', $bits);
	}

	protected static function ctlFn_template (&$opts, &$ctx) {
		$model = $opts['data'] ? self::evaluate_expression($opts['data'], $ctx) : $ctx->m;
		$tpl = self::getTemplate($opts['tpl'], $ctx);
		$newCtx = self::createChildCtx($ctx, $model);
		if ($tpl) {
			return self::render_context($tpl, $newCtx);
		}
	}

	protected static function ctlFn_with (&$opts, &$ctx) {
		$model = $opts['data'] ? self::evaluate_expression($opts['data'], $ctx) : $ctx->m;
		$tpl = self::getTemplate($opts['tpl'], $ctx);
		if ($model && $tpl) {
			$newCtx = self::createChildCtx($ctx, $model);
			return self::render_context($tpl, $newCtx);
		}
	}

	protected static function ctlFn_if (&$opts, &$ctx) {
		if (self::evaluate_expression($opts['data'], $ctx)) {
			return self::render_context($opts['tpl'], $ctx);
		}
	}

	protected static function ctlFn_ifnot (&$opts, &$ctx) {
		if (!self::evaluate_expression($opts['data'], $ctx)) {
			return self::render_context($opts['tpl'], $ctx);
		}
	}

	protected static function ctlFn_attr (&$opts, &$ctx) {
		foreach($opts as $name => $val) {
			if (is_string($val)) {
				$attVal = self::evaluate_expression($val, $ctx);
			} else {
				// must be an object
				$attVal = $val['v'] ? $val['v'] : '';
				if (is_array($val['app'])) {
					foreach ($val['app'] as $appItem) {
						if (array_key_exists('if', $appItem)
							&& self::evaluate_expression($appItem['if'], $ctx)) {
							$attVal .= $appItem['v'] ? $appItem['v'] : '';
						}
						if (array_key_exists('ifnot', $appItem)
							&& ! self::evaluate_expression($appItem['ifnot'], $ctx)) {
							$attVal .= $appItem['v'] ? $appItem['v'] : '';
						}
					}
				}
				if (!$attVal && $val['v'] == null) {
					$attVal = null;
				}
			}
			/*
			 * TODO: hook up sanitization to MW sanitizer via options?
			if ($attVal != null) {
				if ($name == 'href' || $name == 'src') {
					$attVal = self::sanitizeHref($attVal);
				} else if ($name == 'style') {
					$attVal = self::sanitizeStyle($attVal);
				}
			}
			 */
			if ($attVal != null) {
				if ( defined( 'ENT_XML1' ) ) {
					$escaped = htmlspecialchars( $attVal, ENT_XML1 | ENT_COMPAT ) . '"';
				} else {
					$escaped = htmlspecialchars( $attVal, ENT_COMPAT ) . '"';
				}
				return ' ' . $name . '="' . $escaped;

			}
		}
	}
}

class TAssemblyOptions {
	public $partials = array();
	public $globals = array();
}

class TAssemblyException extends \Exception {
	public function __construct($message = "", $ir = '', $code = 0, Exception $previous = null) {
		parent::__construct($message,$code,$previous); // TODO: Change the autogenerated stub
	}
}
