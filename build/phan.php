<?php
/**
 * (c) Sergei Shilko <contact@sshilko.com>
 *
 * MIT License
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * @license https://opensource.org/licenses/mit-license.php MIT
 */

declare(strict_types = 1);

use Phan\Issue;

/**
 * This configuration will be read and overlaid on top of the
 * default configuration. Command-line arguments will be applied
 * after this file is read.
 * @phpcs:disable
 */
return [
    // The number of processes to fork off during the analysis
    // phase.
    'processes' => 1,

    // The minimum severity level to report on. This can be
    // set to Issue::SEVERITY_LOW, Issue::SEVERITY_NORMAL or
    // Issue::SEVERITY_CRITICAL.
    'minimum_severity' => Issue::SEVERITY_LOW,

    'suppress_issue_types' => [
        'PhanParamNameIndicatingUnusedInClosure'
    ],

    // Supported values: `'5.6'`, `'7.0'`, `'7.1'`, `'7.2'`, `'7.3'`,
    // `'7.4'`, `'8.0'`, `'8.1'`, `null`.
    // If this is set to `null`,
    // then Phan assumes the PHP version which is closest to the minor version
    // of the php executable used to execute Phan.
    //
    // Note that the **only** effect of choosing `'5.6'` is to infer
    // that functions removed in php 7.0 exist.
    // (See `backward_compatibility_checks` for additional options)
    // TODO: Set this.
    'target_php_version' => '8.1',
    'minimum_target_php_version' => '7.4',

    // A list of directories that should be parsed for class and
    // method information. After excluding the directories
    // defined in exclude_analysis_directory_list, the remaining
    // files will be statically analyzed for errors.
    //
    // Thus, both first-party and third-party code being used by
    // your application should be included in this list.
    'directory_list' => [
        'src',
        'vendor',
    ],

    // A regex used to match every file name that you want to
    // exclude from parsing. Actual value will exclude every
    // "test", "tests", "Test" and "Tests" folders found in
    // "vendor/" directory.
    'exclude_file_regex' => '@^vendor/.*/(tests?|Tests?)/@',

    // A directory list that defines files that will be excluded
    // from static analysis, but whose class and method
    // information should be included.
    //
    // Generally, you'll want to include the directories for
    // third-party code (such as "vendor/") in this list.
    //
    // n.b.: If you'd like to parse but not analyze 3rd
    //       party code, directories containing that code
    //       should be added to both the `directory_list`
    //       and `exclude_analysis_directory_list` arrays.
    'exclude_analysis_directory_list' => [
        'vendor/',
    ],

    # ------------------------------------------------------
    # ADVANCED SETTINGS ------------------------------------
    # ------------------------------------------------------

    // Default: true. If this is set to true,
    // and target_php_version is newer than the version used to run Phan,
    // Phan will act as though functions added in newer PHP versions exist.
    //
    // NOTE: Currently, this only affects Closure::fromCallable
    'pretend_newer_core_functions_exist' => true,

    // If true, missing properties will be created when
    // they are first seen. If false, we'll report an
    // error message.
    'allow_missing_properties' => false,

    // Allow null to be cast as any type and for any
    // type to be cast to null.
    'null_casts_as_any_type' => false,

    // Allow null to be cast as any array-like type
    // This is an incremental step in migrating away from null_casts_as_any_type.
    // If null_casts_as_any_type is true, this has no effect.
    'null_casts_as_array' => false,

    // Allow any array-like type to be cast to null.
    // This is an incremental step in migrating away from null_casts_as_any_type.
    // If null_casts_as_any_type is true, this has no effect.
    'array_casts_as_null' => false,

    // If enabled, Phan will warn if **any** type in a method invocation's object
    // is definitely not an object,
    // or if **any** type in an invoked expression is not a callable.
    // Setting this to true will introduce numerous false positives
    // (and reveal some bugs).
    'strict_method_checking' => true,

    // If enabled, Phan will warn if **any** type in the argument's union type
    // cannot be cast to a type in the parameter's expected union type.
    // Setting this to true will introduce numerous false positives
    // (and reveal some bugs).
    'strict_param_checking' => true,

    // If enabled, Phan will warn if **any** type in a property assignment's union type
    // cannot be cast to a type in the property's declared union type.
    // Setting this to true will introduce numerous false positives
    // (and reveal some bugs).
    // (For self-analysis, Phan has a large number of suppressions and file-level suppressions, due to \ast\Node being difficult to type check)
    'strict_property_checking' => true,

    // If enabled, Phan will warn if **any** type in a returned value's union type
    // cannot be cast to the declared return type.
    // Setting this to true will introduce numerous false positives
    // (and reveal some bugs).
    // (For self-analysis, Phan has a large number of suppressions and file-level suppressions, due to \ast\Node being difficult to type check)
    'strict_return_checking' => true,

    // If enabled, Phan will warn if **any** type of the object expression for a property access
    // does not contain that property.
    'strict_object_checking' => true,

    // If enabled, scalars (int, float, bool, string, null)
    // are treated as if they can cast to each other.
    // This does not affect checks of array keys. See `scalar_array_key_cast`.
    'scalar_implicit_cast' => false,

    // If enabled, any scalar array keys (int, string)
    // are treated as if they can cast to each other.
    // E.g. `array<int,stdClass>` can cast to `array<string,stdClass>` and vice versa.
    // Normally, a scalar type such as int could only cast to/from int and mixed.
    'scalar_array_key_cast' => false,

    // If this has entries, scalars (int, float, bool, string, null)
    // are allowed to perform the casts listed.
    //
    // E.g. `['int' => ['float', 'string'], 'float' => ['int'], 'string' => ['int'], 'null' => ['string']]`
    // allows casting null to a string, but not vice versa.
    // (subset of `scalar_implicit_cast`)
    'scalar_implicit_partial' => [],

    // If true, Phan will convert the type of a possibly undefined array offset to the nullable, defined equivalent.
    // If false, Phan will convert the type of a possibly undefined array offset to the defined equivalent (without converting to nullable).
    'convert_possibly_undefined_offset_to_nullable' => false,

    // If true, seemingly undeclared variables in the global
    // scope will be ignored.
    //
    // This is useful for projects with complicated cross-file
    // globals that you have no hope of fixing.
    'ignore_undeclared_variables_in_global_scope' => false,

    // Backwards Compatibility Checking (This is very slow)
    'backward_compatibility_checks' => false,

    // If true, check to make sure the return type declared
    // in the doc-block (if any) matches the return type
    // declared in the method signature.
    'check_docblock_signature_return_type_match' => true,

    // If true, check to make sure the param types declared
    // in the doc-block (if any) matches the param types
    // declared in the method signature.
    'check_docblock_signature_param_type_match' => true,

    // If true, make narrowed types from phpdoc params override
    // the real types from the signature, when real types exist.
    // (E.g. allows specifying desired lists of subclasses,
    //  or to indicate a preference for non-nullable types over nullable types)
    //
    // Affects analysis of the body of the method and the param types passed in by callers.
    //
    // (*Requires `check_docblock_signature_param_type_match` to be true*)
    'prefer_narrowed_phpdoc_param_type' => true,

    // (*Requires `check_docblock_signature_return_type_match` to be true*)
    //
    // If true, make narrowed types from phpdoc returns override
    // the real types from the signature, when real types exist.
    //
    // (E.g. allows specifying desired lists of subclasses,
    //  or to indicate a preference for non-nullable types over nullable types)
    // Affects analysis of return statements in the body of the method and the return types passed in by callers.
    'prefer_narrowed_phpdoc_return_type' => true,

    // If enabled, check all methods that override a
    // parent method to make sure its signature is
    // compatible with the parent's. This check
    // can add quite a bit of time to the analysis.
    // This will also check if final methods are overridden, etc.
    'analyze_signature_compatibility' => true,

    // Set this to true to make Phan guess that undocumented parameter types
    // (for optional parameters) have the same type as default values
    // (Instead of combining that type with `mixed`).
    // E.g. `function($x = 'val')` would make Phan infer that $x had a type of `string`, not `string|mixed`.
    // Phan will not assume it knows specific types if the default value is false or null.
    'guess_unknown_parameter_type_using_default' => false,

    // Allow adding types to vague return types such as @return object, @return ?mixed in function/method/closure union types.
    // Normally, Phan only adds inferred returned types when there is no `@return` type or real return type signature..
    // This setting can be disabled on individual methods by adding `@phan-hardcode-return-type` to the doc comment.
    //
    // Disabled by default. This is more useful with `--analyze-twice`.
    'allow_overriding_vague_return_types' => true,

    // When enabled, infer that the types of the properties of `$this` are equal to their default values at the start of `__construct()`.
    // This will have some false positives due to Phan not checking for setters and initializing helpers.
    // This does not affect inherited properties.
    'infer_default_properties_in_construct' => true,

    // Set this to true to enable the plugins that Phan uses to infer more accurate return types of `implode`, `json_decode`, and many other functions.
    //
    // Phan is slightly faster when these are disabled.
    'enable_extended_internal_return_type_plugins' => true,

    // This setting maps case-insensitive strings to union types.
    //
    // This is useful if a project uses phpdoc that differs from the phpdoc2 standard.
    //
    // If the corresponding value is the empty string,
    // then Phan will ignore that union type (E.g. can ignore 'the' in `@return the value`)
    //
    // If the corresponding value is not empty,
    // then Phan will act as though it saw the corresponding UnionTypes(s)
    // when the keys show up in a UnionType of `@param`, `@return`, `@var`, `@property`, etc.
    //
    // This matches the **entire string**, not parts of the string.
    // (E.g. `@return the|null` will still look for a class with the name `the`, but `@return the` will be ignored with the below setting)
    //
    // (These are not aliases, this setting is ignored outside of doc comments).
    // (Phan does not check if classes with these names exist)
    //
    // Example setting: `['unknown' => '', 'number' => 'int|float', 'char' => 'string', 'long' => 'int', 'the' => '']`
    'phpdoc_type_mapping' => [ ],

    // Set to true in order to attempt to detect dead
    // (unreferenced) code. Keep in mind that the
    // results will only be a guess given that classes,
    // properties, constants and methods can be referenced
    // as variables (like `$class->$property` or
    // `$class->$method()`) in ways that we're unable
    // to make sense of.
    //
    // To more aggressively detect dead code,
    // you may want to set `dead_code_detection_prefer_false_negative` to `false`.
    'dead_code_detection' => false,

    // Set to true in order to attempt to detect unused variables.
    // `dead_code_detection` will also enable unused variable detection.
    //
    // This has a few known false positives, e.g. for loops or branches.
    'unused_variable_detection' => false,

    // Set to true in order to force tracking references to elements
    // (functions/methods/consts/protected).
    // dead_code_detection is another option which also causes references
    // to be tracked.
    'force_tracking_references' => false,

    // Set to true in order to attempt to detect redundant and impossible conditions.
    //
    // This has some false positives involving loops,
    // variables set in branches of loops, and global variables.
    'redundant_condition_detection' => true,

    // Set to true in order to attempt to detect error-prone truthiness/falsiness checks.
    //
    // This is not suitable for all codebases.
    'error_prone_truthy_condition_detection' => true,

    // Enable this to warn about harmless redundant use for classes and namespaces such as `use Foo\bar` in namespace Foo.
    //
    // Note: This does not affect warnings about redundant uses in the global namespace.
    'warn_about_redundant_use_namespaced_class' => true,

    // If true, then run a quick version of checks that takes less time.
    // False by default.
    'quick_mode' => false,

    // If true, then before analysis, try to simplify AST into a form
    // which improves Phan's type inference in edge cases.
    //
    // This may conflict with 'dead_code_detection'.
    // When this is true, this slows down analysis slightly.
    //
    // E.g. rewrites `if ($a = value() && $a > 0) {...}`
    // into $a = value(); if ($a) { if ($a > 0) {...}}`
    'simplify_ast' => true,

    // If true, Phan will read `class_alias` calls in the global scope,
    // then (1) create aliases from the *parsed* files if no class definition was found,
    // and (2) emit issues in the global scope if the source or target class is invalid.
    // (If there are multiple possible valid original classes for an aliased class name,
    //  the one which will be created is unspecified.)
    // NOTE: THIS IS EXPERIMENTAL, and the implementation may change.
    'enable_class_alias_support' => false,

    // Enable or disable support for generic templated
    // class types.
    'generic_types_enabled' => true,

    // If enabled, warn about throw statement where the exception types
    // are not documented in the PHPDoc of functions, methods, and closures.
    'warn_about_undocumented_throw_statements' => true,

    // If enabled (and warn_about_undocumented_throw_statements is enabled),
    // warn about function/closure/method calls that have (at)throws
    // without the invoking method documenting that exception.
    'warn_about_undocumented_exceptions_thrown_by_invoked_functions' => true,
];
