<?php
declare(strict_types=1);

namespace Vodacek\Forms\Controls;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use Nette\Forms\Container;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Form;
use Nette\Forms\Control;
use Nette\Forms\Validator;

class DateInput extends BaseControl
{
	public const
		TYPE_DATETIME_LOCAL = 'datetime-local',
		TYPE_DATE = 'date',
		TYPE_MONTH = 'month',
		TYPE_TIME = 'time',
		TYPE_WEEK = 'week';

	protected string $type;

	protected array $range = ['min' => null, 'max' => null];

	protected ?string $submittedValue = null;

	private string $dateTimeClass = DateTime::class;

	public static string $defaultValidMessage = 'Please enter a valid date.';

	public static array $formats = [
		self::TYPE_DATETIME_LOCAL => 'Y-m-d\TH:i:s',
		self::TYPE_DATE => 'Y-m-d',
		self::TYPE_MONTH => 'Y-m',
		self::TYPE_TIME => 'H:i:s',
		self::TYPE_WEEK => 'o-\WW'
	];

	public static function register($immutable = true): void
	{
		Container::extensionMethod('addDate2', static function (
			Container $form,
			string $name,
			?string $label = null,
			string $type = self::TYPE_DATETIME_LOCAL
		) use ($immutable) {
			$component = new static($label, $type, $immutable);
			$form->addComponent($component, $name);
			$component->setRequired(false);
			$component->addRule([__CLASS__, 'validateValid'], static::$defaultValidMessage);
			return $component;
		});
		Validator::$messages[__CLASS__.'::validateDateInputRange'] = Validator::$messages[Form::RANGE];
	}

	/**
	 * @throws InvalidArgumentException
	 */
	public function __construct(?string $label = null, string $type = self::TYPE_DATETIME_LOCAL, bool $immutable = true)
	{
		if (!isset(static::$formats[$type])) {
			throw new InvalidArgumentException("invalid type '$type' given.");
		}
		parent::__construct($label);
		$this->control->type = $this->type = $type;

		if ($immutable) {
			$this->dateTimeClass = DateTimeImmutable::class;
		}
	}

	public function setValue($value = null): DateInput
	{
		if ($value === null || $value instanceof DateTimeInterface) {
			$this->value = $value;
			$this->submittedValue = null;
		} elseif ($value instanceof DateInterval) {
			$this->value = $this->createFromFormat(static::$formats[self::TYPE_TIME], $value->format('%H:%I:%S'));
			$this->submittedValue = null;
		} elseif (is_string($value)) {
			if ($value === '') {
				$this->value = null;
				$this->submittedValue = null;
			} else {
				$this->value = $this->parseValue($value);
				if ($this->value !== null) {
					$this->submittedValue = null;
				} else {
					$this->value = null;
					$this->submittedValue = $value;
				}
			}
		} else {
			$this->submittedValue = $value;
			throw new InvalidArgumentException("Invalid type for $value.");
		}
		return $this;
	}

	/**
	 * @throws Exception
	 */
	public function getControl() {
		$control = parent::getControl();
		$format = static::$formats[$this->type];
		$data['format'] = DateInput::$formats[$this->type];
		if ($this->value !== null) {
			$control->value = $this->value->format($format);
			$data['value'] = $this->value->format('Y-m-d H:i:s');
		}
		if (is_string($this->submittedValue)) {
			$control->value = $this->submittedValue;
			$data['value'] = (new DateTime($this->submittedValue))->format('Y-m-d H:i:s');
		}
		if ($this->range['min'] !== null) {
			$control->min = $this->range['min']->format($format);
			$data['minDate'] = $this->range['min']->format('Y-m-d');
			$data['minTime'] = $this->range['min']->format('H:i');
		}
		if ($this->range['max'] !== null) {
			$control->max = $this->range['max']->format($format);
			$data['maxDate'] = $this->range['max']->format('Y-m-d');
			$data['maxTime'] = $this->range['max']->format('H:i');
		}
		$control->data('adt-date-input', json_encode($data));

		return $control;
	}

	public function addRule($validator, $errorMessage = null, $arg = null): DateInput
	{
		if ($validator === Form::RANGE) {
			$this->range['min'] = $this->normalizeDate($arg[0]);
			$this->range['max'] = $this->normalizeDate($arg[1]);
			$validator = __CLASS__.'::validateDateInputRange';
			$arg[0] = $this->formatDate($arg[0]);
			$arg[1] = $this->formatDate($arg[1]);
		}

		return parent::addRule($validator, $errorMessage, $arg);
	}

	public static function validateFilled(Control $control): bool
	{
		if (!$control instanceof self) {
			throw new InvalidArgumentException("Can't validate control '". get_class($control)."'.");
		}

		return ($control->value !== null || $control->submittedValue !== null);
	}

	public static function validateValid(Control $control): bool
	{
		if (!$control instanceof self) {
			throw new InvalidArgumentException("Can't validate control '". get_class($control)."'.");
		}

		return $control->submittedValue === null;
	}

	public static function validateDateInputRange(self $control): bool {
		if (($control->range['min'] !== null) && $control->range['min'] > $control->value) {
			return false;
		}

		if (($control->range['max'] !== null) && $control->range['max'] < $control->value) {
			return false;
		}

		return true;
	}

	private function parseValue(string $value): ?DateTimeInterface
	{
		if ($this->type === self::TYPE_WEEK) {
			try {
				$date = $this->createDateTime($value. '1');
			} catch (Exception $e) {
				$date = null;
			}
		} else {
			$date = $this->createFromFormat('!'.static::$formats[$this->type], $value);
		}

		return $date;
	}

	private function formatDate(?DateTimeInterface $value = null): ?string
	{
		if ($value === null) {
			return null;
		}

		return $value->format(static::$formats[$this->type]);
	}

	private function normalizeDate(?DateTimeInterface $value): ?DateTimeInterface
	{
		if ($value === null) {
			return null;
		}

		return $this->parseValue($this->formatDate($value));
	}

	private function createDateTime(string $string): DateTimeInterface
	{
		return new $this->dateTimeClass($string);
	}

	private function createFromFormat(string $string): ?DateTimeInterface
	{
		$val = call_user_func_array([$this->dateTimeClass, 'createFromFormat'], func_get_args());

		return $val === false ? null : $val;
	}

	public function isFilled(): bool
	{
		return parent::isFilled() || $this->submittedValue;
	}
}
