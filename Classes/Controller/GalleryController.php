<?php

declare(strict_types=1);

/*
 * This file is part of the "Image Gallery" extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * Jens Neumann <info@freshworkx.de>
 */

namespace Freshworkx\BmImageGallery\Controller;

use Exception;
use Freshworkx\BmImageGallery\PageTitle\GalleryPageTitleProvider;
use Freshworkx\BmImageGallery\Resource\Collection\CategoryBasedFileCollection;
use Freshworkx\BmImageGallery\Resource\Collection\FolderBasedFileCollection;
use Freshworkx\BmImageGallery\Resource\Collection\StaticFileCollection;
use Freshworkx\BmImageGallery\Resource\GalleryCollectionRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Pagination\ArrayPaginator;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Resource\Collection\AbstractFileCollection;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\FileCollectionRepository;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Frontend\Resource\FileCollector;

class GalleryController extends ActionController
{
    public function __construct(
        private readonly GalleryCollectionRepository $galleryCollectionRepository,
        private readonly FileRepository           $fileRepository,
        private readonly LoggerInterface          $logger,
        private readonly GalleryPageTitleProvider $galleryPageTitleProvider
    ) {
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function listAction(): ResponseInterface
    {
        $fileCollections = [];
        if($this->settings['list']['showAllAvailableCollections'] === '1') {
            $storagePid = $this->settings['list']['storagePid'] ?? 0;
            $fileCollections = $this->galleryCollectionRepository->findByPid((int)$storagePid);
        } else {
            $currentContentObject = $this->request->getAttribute('currentContentObject');
            $collections = GeneralUtility::trimExplode(',', $currentContentObject->data['file_collections'], true);
            foreach ($collections as $collectionUid) {
                $fileCollection = $this->findByUid((int)$collectionUid);
                if($fileCollection !== null) {
                    $fileCollections[] = $fileCollection;
                }

            }
        }
        $collectionInfos = [];
        foreach ($fileCollections as $fileCollection) {
            $collectionInfo = $this->getCollectionInfo($fileCollection, false);
            if ($collectionInfo !== []) {
                $collectionInfos[] = $collectionInfo;
            }
        }

        $this->view->assign('collectionInfos', $collectionInfos);
        return $this->htmlResponse();
    }

    public function galleryAction(): ResponseInterface
    {
        $identifier = $this->request->getAttribute('currentContentObject')->data['file_collections'];
        $fileCollection = $this->findByUid((int)$identifier);
        $this->view->assign('fileCollection', $this->getCollectionInfo($fileCollection ,true));
        return $this->htmlResponse();
    }

    public function detailAction(): ResponseInterface
    {
        $queryParams = $this->request->getQueryParams();
        $identifier = $queryParams['tx_bmimagegallery_gallerylist']['show'] ?? 0;

        $fileCollection = $this->findByUid((int)$identifier);
        $collectionInfo = $this->getCollectionInfo($fileCollection, true);

        $this->view->assign('fileCollection', $collectionInfo);
        return $this->htmlResponse();
    }

    protected function findByUid(int $identifier): ?AbstractFileCollection
    {
        try {
            return $this->galleryCollectionRepository->findByUid($identifier);
        } catch (ResourceDoesNotExistException $exception) {
            $this->logger->log(LogLevel::ERROR, $exception->getMessage());
            $this->logger->log(
                LogLevel::WARNING,
                sprintf(
                    'The file-collection with ID "%s" could not be loaded and won\'t be included',
                    $identifier
                )
            );
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCollectionInfo($fileCollection, bool $withItems = false): array
    {
        // return if collection not found
        if ($fileCollection === null) {
            return [];
        }

        // load contents and get items
        $fileCollection->loadContents();
        $items = $fileCollection->getItems();

        // return if collection is empty
        if (count($items) === 0) {
            return [];
        }

        // gallery description with fallback to description of collection
        $description = ($fileCollection->getGalleryDescription() !== '') ? $fileCollection->getGalleryDescription() : $fileCollection->getDescription();

        // preview image with fallback to first image of collection (old behavior)
        $previewImage = $this->fileRepository->findByRelation(
            'sys_file_collection',
            'bm_image_gallery_preview_image',
            $fileCollection->getUid()
        );
        $previewImage = ($previewImage === []) ? reset($items) : reset($previewImage);

        // return infos
        return [
            'identifier' => $fileCollection->getUid(),
            'itemCount' => count($items),
            'title' => $fileCollection->getTitle(),
            'location' => $fileCollection->getGalleryLocation(),
            'description' => $description,
            'date' => $fileCollection->getGalleryDate(),
            'previewImage' => $previewImage,
            'items' => ($withItems) ? $this->sortAndLimitItems($fileCollection) : [],
        ];
    }

    /**
     * @return array<int, FileInterface>
     */
    protected function sortAndLimitItems(AbstractFileCollection $fileCollection): array
    {
        /** @var FileCollector $fileCollector */
        $fileCollector = GeneralUtility::makeInstance(FileCollector::class);
        $fileCollector->addFilesFromFileCollection($fileCollection->getUid());
        $fileCollector->sort($this->settings['orderBy'], $this->settings['sortingOrder']);

        $files = $fileCollector->getFiles();

        $maxItems = (int)$this->settings['maxItems'];
        if ($maxItems > 0) {
            return array_slice($files, 0, $maxItems);
        } else {
            $currentPage = $this->request->hasArgument('page')
                ? (int)$this->request->getArgument('page')
                : 1;
            $itemsPerPage = 20;
            $paginator = new ArrayPaginator($files, $currentPage, $itemsPerPage);
            $pagination = new SimplePagination($paginator);

            $this->view->assignMultiple([
                'currentPage' => $currentPage,
                'paginator' => $paginator,
                'pagination' => $pagination
            ]);
        }

        return $files;
    }

    public function headerAction(): ResponseInterface
    {
        $title = 'Galerie';
        $queryParams = $this->request->getQueryParams();
        $identifier = $queryParams['tx_bmimagegallery_gallerylist']['show'] ?? 0;
        if($identifier !== 0) {
            $fileCollection = $this->findByUid((int)$identifier);
            $collectionInfo = $this->getCollectionInfo($fileCollection, false);
            $title = 'Galerie - ' . $collectionInfo['title'];
        }
        $this->galleryPageTitleProvider->setTitle($title);
        $this->view->assign('title', $title);
        return $this->htmlResponse();
    }
}
