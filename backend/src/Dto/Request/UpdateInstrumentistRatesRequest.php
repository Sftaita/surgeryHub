<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class UpdateInstrumentistRatesRequest
{
    #[Assert\Type(type: 'numeric', message: 'This value should be numeric.')]
    #[Assert\GreaterThanOrEqual(value: 0, message: 'This value should be greater than or equal to 0.')]
    public mixed $hourlyRate = null;

    #[Assert\Type(type: 'numeric', message: 'This value should be numeric.')]
    #[Assert\GreaterThanOrEqual(value: 0, message: 'This value should be greater than or equal to 0.')]
    public mixed $consultationFee = null;

    #[Assert\Callback]
    public function validateAtLeastOneField(ExecutionContextInterface $context): void
    {
        if ($this->hourlyRate === null && $this->consultationFee === null) {
            $context->buildViolation('At least one of hourlyRate or consultationFee is required.')
                ->addViolation();
        }
    }
}