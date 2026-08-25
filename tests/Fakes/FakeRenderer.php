<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\InvoicesAndDocuments\Filament\Tests\Fakes;

use Liberu\Ecommerce\InvoicesAndDocuments\Contracts\DocumentRenderer;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\Rendered;
use Liberu\Ecommerce\InvoicesAndDocuments\Data\RenderModel;

final class FakeRenderer implements DocumentRenderer
{
    public function render(RenderModel $model): ?Rendered
    {
        return new Rendered('application/pdf', ($model->number ?? $model->reference).'.pdf', 'bytes');
    }
}
