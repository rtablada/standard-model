<?php namespace Rtablada;

use Closure;
use DateTime;
use ArrayAccess;
use Carbon\Carbon;
use Illuminate\Support\Contracts\JsonableInterface;
use Illuminate\Support\Contracts\ArrayableInterface;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\MassAssignmentException;

abstract class StandardModel implements ArrayAccess, ArrayableInterface, JsonableInterface
{

	/**
	 * The model's attributes.
	 *
	 * @var array
	 */
	protected $attributes = array();

	/**
	 * Dictionary used to translate item keys to something
	 * more usable.
	 *
	 * @var array
	 */
	protected $dictionary = null;

	/**
	 * The model attribute's original state.
	 *
	 * @var array
	 */
	protected $original = array();

	/**
	 * The attributes that should be hidden for arrays.
	 *
	 * @var array
	 */
	protected $hidden = array();

	/**
	 * The attributes that should be visible in arrays.
	 *
	 * @var array
	 */
	protected $visible = array();

	/**
	 * The accessors to append to the model's array form.
	 *
	 * @var array
	 */
	protected $appends = array();

	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = array();

	/**
	 * The attributes that aren't mass assignable.
	 *
	 * @var array
	 */
	protected $guarded = array('*');

	/**
	 * The attributes that should be mutated to dates.
	 *
	 * @var array
	 */
	protected $dates = array();

	/**
	 * Indicates whether attributes are snake cased on arrays.
	 *
	 * @var bool
	 */
	public static $snakeAttributes = true;

	/**
	 * The array of booted models.
	 *
	 * @var array
	 */
	protected static $booted = array();

	/**
	 * Indicates if all mass assignment is enabled.
	 *
	 * @var bool
	 */
	protected static $unguarded = false;

	/**
	 * The cache of the mutated attributes for each class.
	 *
	 * @var array
	 */
	protected static $mutatorCache = array();

	/**
	 * Create a new Eloquent model instance.
	 *
	 * @param  array  $attributes
	 * @return void
	 */
	public function __construct(array $attributes = array())
	{
		if ( ! isset(static::$booted[get_class($this)]))
		{
			static::$booted[get_class($this)] = true;

			static::boot();
		}

		$this->fill($attributes);
	}

	/**
	 * The "booting" method of the model.
	 *
	 * @return void
	 */
	protected static function boot()
	{
		$class = get_called_class();

		static::$mutatorCache[$class] = array();

		// Here we will extract all of the mutated attributes so that we can quickly
		// spin through them after we export models to their array form, which we
		// need to be fast. This will let us always know the attributes mutate.
		foreach (get_class_methods($class) as $method)
		{
			if (preg_match('/^get(.+)Attribute$/', $method, $matches))
			{
				if (static::$snakeAttributes) $matches[1] = snake_case($matches[1]);

				static::$mutatorCache[$class][] = lcfirst($matches[1]);
			}
		}
	}

	/**
	 * Fill the model with an array of attributes.
	 *
	 * @param  array  $attributes
	 * @return \Illuminate\Database\Eloquent\Model|static
	 */
	public function fill(array $attributes)
	{
		if ($this->dictionary) {
			$oldAttributes = $attributes;
			$attributes = array();

			foreach ($this->dictionary as $term => $key) {
				if (isset($attributes[$key])) {
					$attributes[$key] = $oldAttributes[$term];
				}
			}
		}

		foreach ($this->fillableFromArray($attributes) as $key => $value)
		{
			// The developers may choose to place some attributes in the "fillable"
			// array, which means only those attributes may be set through mass
			// assignment to the model, and all others will just be ignored.
			if ($this->isFillable($key))
			{
				$this->setAttribute($key, $value);
			}
			elseif ($this->totallyGuarded())
			{
				throw new MassAssignmentException($key);
			}
		}

		return $this;
	}

	/**
	 * Get the fillable attributes of a given array.
	 *
	 * @param  array  $attributes
	 * @return array
	 */
	protected function fillableFromArray(array $attributes)
	{
		if (count($this->fillable) > 0 and ! static::$unguarded)
		{
			return array_intersect_key($attributes, array_flip($this->fillable));
		}

		return $attributes;
	}

	/**
	 * Create a new instance of the given model.
	 *
	 * @param  array  $attributes
	 * @param  bool   $exists
	 * @return \Illuminate\Database\Eloquent\Model|static
	 */
	public function newInstance($attributes = array())
	{
		// This method just provides a convenient way for us to generate fresh model
		// instances of this current model. It is particularly useful during the
		// hydration of new objects via the Eloquent query builder instances.
		$model = new static((array) $attributes);

		return $model;
	}

	public function createCollectionFromItems($items = array())
	{
		$models = $this->getArrayOfInstances($items);

		return $this->newCollection($models);
	}

	/**
	 * Create a new Eloquent Collection instance.
	 *
	 * @param  array  $models
	 * @return \Illuminate\Database\Eloquent\Collection
	 */
	public function newCollection(array $models = array())
	{
		return new Collection($models);
	}

	protected function getArrayOfInstances($items = array())
	{
		$models = array();

		foreach ($items as $item) {
			$model = $this->newInstance($item);

			$models[] = $model;
		}

		return $models;
	}

	public function makePaginator($items = array(), $total, $perPage)
	{
		$models = $this->getArrayOfInstances($items);

		return \Paginator::make($models, $total, $perPage);
	}

	/**
	 * Get a fresh timestamp for the model.
	 *
	 * @return \Carbon\Carbon
	 */
	public function freshTimestamp()
	{
		return new Carbon;
	}

	/**
	 * Get a fresh timestamp for the model.
	 *
	 * @return string
	 */
	public function freshTimestampString()
	{
		return $this->fromDateTime($this->freshTimestamp());
	}

	/**
	 * Get the hidden attributes for the model.
	 *
	 * @return array
	 */
	public function getHidden()
	{
		return $this->hidden;
	}

	/**
	 * Set the hidden attributes for the model.
	 *
	 * @param  array  $hidden
	 * @return void
	 */
	public function setHidden(array $hidden)
	{
		$this->hidden = $hidden;
	}

	/**
	 * Set the visible attributes for the model.
	 *
	 * @param  array  $visible
	 * @return void
	 */
	public function setVisible(array $visible)
	{
		$this->visible = $visible;
	}

	/**
	 * Set the accessors to append to model arrays.
	 *
	 * @param  array  $appends
	 * @return void
	 */
	public function setAppends(array $appends)
	{
		$this->appends = $appends;
	}

	/**
	 * Get the fillable attributes for the model.
	 *
	 * @return array
	 */
	public function getFillable()
	{
		return $this->fillable;
	}

	/**
	 * Set the fillable attributes for the model.
	 *
	 * @param  array  $fillable
	 * @return \Illuminate\Database\Eloquent\Model
	 */
	public function fillable(array $fillable)
	{
		$this->fillable = $fillable;

		return $this;
	}

	/**
	 * Set the guarded attributes for the model.
	 *
	 * @param  array  $guarded
	 * @return \Illuminate\Database\Eloquent\Model
	 */
	public function guard(array $guarded)
	{
		$this->guarded = $guarded;

		return $this;
	}

	/**
	 * Disable all mass assignable restrictions.
	 *
	 * @return void
	 */
	public static function unguard()
	{
		static::$unguarded = true;
	}

	/**
	 * Enable the mass assignment restrictions.
	 *
	 * @return void
	 */
	public static function reguard()
	{
		static::$unguarded = false;
	}

	/**
	 * Set "unguard" to a given state.
	 *
	 * @param  bool  $state
	 * @return void
	 */
	public static function setUnguardState($state)
	{
		static::$unguarded = $state;
	}

	/**
	 * Determine if the given attribute may be mass assigned.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function isFillable($key)
	{
		if (static::$unguarded) return true;

		// If the key is in the "fillable" array, we can of course assume that it's
		// a fillable attribute. Otherwise, we will check the guarded array when
		// we need to determine if the attribute is black-listed on the model.
		if (in_array($key, $this->fillable)) return true;

		if ($this->isGuarded($key)) return false;

		return empty($this->fillable) and ! starts_with($key, '_');
	}

	/**
	 * Determine if the given key is guarded.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function isGuarded($key)
	{
		return in_array($key, $this->guarded) or $this->guarded == array('*');
	}

	/**
	 * Determine if the model is totally guarded.
	 *
	 * @return bool
	 */
	public function totallyGuarded()
	{
		return count($this->fillable) == 0 and $this->guarded == array('*');
	}

	/**
	 * Convert the model instance to JSON.
	 *
	 * @param  int  $options
	 * @return string
	 */
	public function toJson($options = 0)
	{
		return json_encode($this->toArray(), $options);
	}

	/**
	 * Convert the model instance to an array.
	 *
	 * @return array
	 */
	public function toArray()
	{
		return $this->attributesToArray();
	}

	/**
	 * Convert the model's attributes to an array.
	 *
	 * @return array
	 */
	public function attributesToArray()
	{
		$attributes = $this->getArrayableAttributes();

		// We want to spin through all the mutated attributes for this model and call
		// the mutator for the attribute. We cache off every mutated attributes so
		// we don't have to constantly check on attributes that actually change.
		foreach ($this->getMutatedAttributes() as $key)
		{
			if ( ! array_key_exists($key, $attributes)) continue;

			$attributes[$key] = $this->mutateAttribute(
				$key, $attributes[$key]
			);
		}

		// Here we will grab all of the appended, calculated attributes to this model
		// as these attributes are not really in the attributes array, but are run
		// when we need to array or JSON the model for convenience to the coder.
		foreach ($this->appends as $key)
		{
			$attributes[$key] = $this->mutateAttribute($key, null);
		}

		return $attributes;
	}

	/**
	 * Get an attribute array of all arrayable attributes.
	 *
	 * @return array
	 */
	protected function getArrayableAttributes()
	{
		return $this->getArrayableItems($this->attributes);
	}

	/**
	 * Get an attribute array of all arrayable values.
	 *
	 * @param  array  $values
	 * @return array
	 */
	protected function getArrayableItems(array $values)
	{
		if (count($this->visible) > 0)
		{
			return array_intersect_key($values, array_flip($this->visible));
		}

		return array_diff_key($values, array_flip($this->hidden));
	}

	/**
	 * Get an attribute from the model.
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	public function getAttribute($key)
	{
		$inAttributes = array_key_exists($key, $this->attributes);

		// If the key references an attribute, we can just go ahead and return the
		// plain attribute value from the model. This allows every attribute to
		// be dynamically accessed through the _get method without accessors.
		if ($inAttributes or $this->hasGetMutator($key))
		{
			return $this->getAttributeValue($key);
		}
	}

	/**
	 * Get a plain attribute (not a relationship).
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	protected function getAttributeValue($key)
	{
		$value = $this->getAttributeFromArray($key);

		// If the attribute has a get mutator, we will call that then return what
		// it returns as the value, which is useful for transforming values on
		// retrieval from the model to a form that is more useful for usage.
		if ($this->hasGetMutator($key))
		{
			return $this->mutateAttribute($key, $value);
		}

		// If the attribute is listed as a date, we will convert it to a DateTime
		// instance on retrieval, which makes it quite convenient to work with
		// date fields without having to create a mutator for each property.
		elseif (in_array($key, $this->getDates()))
		{
			if ($value) return $this->asDateTime($value);
		}

		return $value;
	}

	/**
	 * Get an attribute from the $attributes array.
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	protected function getAttributeFromArray($key)
	{
		if (array_key_exists($key, $this->attributes))
		{
			return $this->attributes[$key];
		}
	}

	/**
	 * Determine if a get mutator exists for an attribute.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function hasGetMutator($key)
	{
		return method_exists($this, 'get'.studly_case($key).'Attribute');
	}

	/**
	 * Get the value of an attribute using its mutator.
	 *
	 * @param  string  $key
	 * @param  mixed   $value
	 * @return mixed
	 */
	protected function mutateAttribute($key, $value)
	{
		return $this->{'get'.studly_case($key).'Attribute'}($value);
	}

	/**
	 * Set a given attribute on the model.
	 *
	 * @param  string  $key
	 * @param  mixed   $value
	 * @return void
	 */
	public function setAttribute($key, $value)
	{
		// First we will check for the presence of a mutator for the set operation
		// which simply lets the developers tweak the attribute as it is set on
		// the model, such as "json_encoding" an listing of data for storage.
		if ($this->hasSetMutator($key))
		{
			$method = 'set'.studly_case($key).'Attribute';

			return $this->{$method}($value);
		}

		// If an attribute is listed as a "date", we'll convert it from a DateTime
		// instance into a form proper for storage on the database tables using
		// the connection grammar's date format. We will auto set the values.
		elseif (in_array($key, $this->getDates()))
		{
			if ($value)
			{
				$value = $this->fromDateTime($value);
			}
		}

		$this->attributes[$key] = $value;
	}

	/**
	 * Determine if a set mutator exists for an attribute.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function hasSetMutator($key)
	{
		return method_exists($this, 'set'.studly_case($key).'Attribute');
	}

	/**
	 * Get the attributes that should be converted to dates.
	 *
	 * @return array
	 */
	public function getDates()
	{
		return $this->dates;
	}

	/**
	 * Convert a DateTime to a storable string.
	 *
	 * @param  \DateTime|int  $value
	 * @return string
	 */
	public function fromDateTime($value)
	{
		$format = $this->getDateFormat();

		// If the value is already a DateTime instance, we will just skip the rest of
		// these checks since they will be a waste of time, and hinder performance
		// when checking the field. We will just return the DateTime right away.
		if ($value instanceof DateTime)
		{
			//
		}

		// If the value is totally numeric, we will assume it is a UNIX timestamp and
		// format the date as such. Once we have the date in DateTime form we will
		// format it according to the proper format for the database connection.
		elseif (is_numeric($value))
		{
			$value = Carbon::createFromTimestamp($value);
		}

		// If the value is in simple year, month, day format, we will format it using
		// that setup. This is for simple "date" fields which do not have hours on
		// the field. This conveniently picks up those dates and format correct.
		elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value))
		{
			$value = Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
		}

		// If this value is some other type of string, we'll create the DateTime with
		// the format used by the database connection. Once we get the instance we
		// can return back the finally formatted DateTime instances to the devs.
		elseif ( ! $value instanceof DateTime)
		{
			$value = Carbon::createFromFormat($format, $value);
		}

		return $value->format($format);
	}

	/**
	 * Return a timestamp as DateTime object.
	 *
	 * @param  mixed  $value
	 * @return \Carbon\Carbon
	 */
	protected function asDateTime($value)
	{
		// If this value is an integer, we will assume it is a UNIX timestamp's value
		// and format a Carbon object from this timestamp. This allows flexibility
		// when defining your date fields as they might be UNIX timestamps here.
		if (is_numeric($value))
		{
			return Carbon::createFromTimestamp($value);
		}

		// If the value is in simply year, month, day format, we will instantiate the
		// Carbon instances from that fomrat. Again, this provides for simple date
		// fields on the database, while still supporting Carbonized conversion.
		elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value))
		{
			return Carbon::createFromFormat('Y-m-d', $value);
		}

		// Finally, we will just assume this date is in the format used by default on
		// the database connection and use that format to create the Carbon object
		// that is returned back out to the developers after we convert it here.
		elseif ( ! $value instanceof DateTime)
		{
			$format = $this->getDateFormat();

			return Carbon::createFromFormat($format, $value);
		}

		return Carbon::instance($value);
	}

	/**
	 * Get the format for database stored dates.
	 *
	 * @return string
	 */
	protected function getDateFormat()
	{
		return $this->getConnection()->getQueryGrammar()->getDateFormat();
	}

	/**
	 * Get all of the current attributes on the model.
	 *
	 * @return array
	 */
	public function getAttributes()
	{
		return $this->attributes;
	}

	/**
	 * Get the mutated attributes for a given instance.
	 *
	 * @return array
	 */
	public function getMutatedAttributes()
	{
		$class = get_class($this);

		if (isset(static::$mutatorCache[$class]))
		{
			return static::$mutatorCache[get_class($this)];
		}

		return array();
	}

	/**
	 * Dynamically retrieve attributes on the model.
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	public function __get($key)
	{
		return $this->getAttribute($key);
	}

	/**
	 * Dynamically set attributes on the model.
	 *
	 * @param  string  $key
	 * @param  mixed   $value
	 * @return void
	 */
	public function __set($key, $value)
	{
		$this->setAttribute($key, $value);
	}

	/**
	 * Determine if the given attribute exists.
	 *
	 * @param  mixed  $offset
	 * @return bool
	 */
	public function offsetExists($offset)
	{
		return isset($this->$offset);
	}

	/**
	 * Get the value for a given offset.
	 *
	 * @param  mixed  $offset
	 * @return mixed
	 */
	public function offsetGet($offset)
	{
		return $this->$offset;
	}

	/**
	 * Set the value for a given offset.
	 *
	 * @param  mixed  $offset
	 * @param  mixed  $value
	 * @return void
	 */
	public function offsetSet($offset, $value)
	{
		$this->$offset = $value;
	}

	/**
	 * Unset the value for a given offset.
	 *
	 * @param  mixed  $offset
	 * @return void
	 */
	public function offsetUnset($offset)
	{
		unset($this->$offset);
	}

	/**
	 * Determine if an attribute exists on the model.
	 *
	 * @param  string  $key
	 * @return void
	 */
	public function __isset($key)
	{
		return (isset($this->attributes[$key]) or
			    ($this->hasGetMutator($key) and ! is_null($this->getAttributeValue($key))));
	}

	/**
	 * Unset an attribute on the model.
	 *
	 * @param  string  $key
	 * @return void
	 */
	public function __unset($key)
	{
		unset($this->attributes[$key]);
	}

	/**
	 * Handle dynamic static method calls into the method.
	 *
	 * @param  string  $method
	 * @param  array   $parameters
	 * @return mixed
	 */
	public static function __callStatic($method, $parameters)
	{
		$instance = new static;

		return call_user_func_array(array($instance, $method), $parameters);
	}

	/**
	 * Convert the model to its string representation.
	 *
	 * @return string
	 */
	public function __toString()
	{
		return $this->toJson();
	}

}
