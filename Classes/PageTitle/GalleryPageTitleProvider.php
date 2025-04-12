<?php

namespace Freshworkx\BmImageGallery\PageTitle;

use TYPO3\CMS\Core\PageTitle\AbstractPageTitleProvider;

class GalleryPageTitleProvider extends AbstractPageTitleProvider
{
    public function setTitle(string $title): void
    {
        $this->title = $title;
    }
}