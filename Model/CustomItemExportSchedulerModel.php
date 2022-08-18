<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Model;;

use DateTimeImmutable;
use DateTimeZone;
use Mautic\CoreBundle\Helper\ExportHelper;
use Mautic\CoreBundle\Model\AbstractCommonModel;
use Mautic\EmailBundle\Helper\MailHelper;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItemExportScheduler;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemRouteProvider;
use MauticPlugin\CustomObjectsBundle\Repository\CustomItemExportSchedulerRepository;
use MauticPlugin\CustomObjectsBundle\Repository\CustomItemRepository;
use MauticPlugin\CustomObjectsBundle\Repository\CustomItemXrefContactRepository;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class CustomItemExportSchedulerModel extends AbstractCommonModel
{
    private const EXPORT_FILE_NAME_DATE_FORMAT = 'Y_m_d_H_i_s';
    private const CUSTOM_ITEM_LIMIT = 200;
    private const CONTACT_LIMIT = 1000000;

    /**
     * @var ExportHelper
     */
    private ExportHelper $exportHelper;

    /**
     * @var MailHelper
     */
    private MailHelper $mailHelper;

    /**
     * @var CustomItemRouteProvider
     */
    private CustomItemRouteProvider $customItemRouteProvider;

    /**
     * @var CustomFieldValueModel
     */
    private CustomFieldValueModel $customFieldValueModel;

    /**
     * @var CustomItemXrefContactRepository
     */
    private CustomItemXrefContactRepository $customItemXrefContactRepository;

    /**
     * @var CustomItemRepository
     */
    private CustomItemRepository $customItemRepository;

    /**
     * @var string
     */
    private string $filePath;

    public function __construct(
        ExportHelper     $exportHelper,
        MailHelper       $mailHelper,
        CustomFieldValueModel $customFieldValueModel,
        CustomItemRouteProvider $customItemRouteProvider,
        CustomItemXrefContactRepository $customItemXrefContactRepository,
        CustomItemRepository $customItemRepository
    )
    {
        $this->exportHelper = $exportHelper;
        $this->mailHelper = $mailHelper;
        $this->customFieldValueModel = $customFieldValueModel;
        $this->customItemRouteProvider = $customItemRouteProvider;
        $this->customItemXrefContactRepository = $customItemXrefContactRepository;
        $this->customItemRepository = $customItemRepository;
    }

    /**
     * @return CustomItemExportSchedulerRepository
     */
    public function getRepository(): CustomItemExportSchedulerRepository
    {
        /** @var CustomItemExportSchedulerRepository $repo */
        $repo = $this->em->getRepository(CustomItemExportScheduler::class);

        return $repo;
    }

    /**
     * @param int $customObjectId
     * @return CustomItemExportScheduler
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function saveEntity(int $customObjectId): CustomItemExportScheduler
    {
        $customItemExportScheduler = new CustomItemExportScheduler();
        $customItemExportScheduler
            ->setUser($this->userHelper->getUser())
            ->setScheduledDateTime(new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setCustomObjectId($customObjectId);

        $this->em->persist($customItemExportScheduler);
        $this->em->flush();

        return $customItemExportScheduler;
    }

    /**
     * @param CustomItemExportScheduler $customItemExportScheduler
     * @return string
     */
    public function processDataAndGetExportFilePath(CustomItemExportScheduler $customItemExportScheduler): string
    {
        $scheduledDateTime = $customItemExportScheduler->getScheduledDateTime();
        $fileName          = 'custom_items_export_'.$scheduledDateTime->format(self::EXPORT_FILE_NAME_DATE_FORMAT).'.csv';

        $filePath = $this->exportHelper->getValidCustomItemExportFileName($fileName);

        $this->filePath = $filePath;

        $this->processCustomItemData($customItemExportScheduler);

        return $this->exportHelper->zipFile($this->filePath, 'custom_item_exports.csv');
    }

    /**
     * @param CustomItemExportScheduler $customItemExportScheduler
     */
    private function processCustomItemData(CustomItemExportScheduler $customItemExportScheduler): void
    {
        $customObjectRepo = $this->em->getRepository(CustomObject::class);

        $customObject = $customObjectRepo->find($customItemExportScheduler->getCustomObject());

        $customFields = $customObject->getCustomFields()->toArray();

        $this->addExportFileHeaderToCsvFile($customFields);

        $this->addCustomItemsToCsvFile($customFields, $customObject);
    }

    /**
     * @param array<CustomField> $customFields
     * @return array<string>
     */
    private function getCSVHeader(array $customFields): array
    {
        $header = ["customItemId", "customItemName"];

        foreach ($customFields as $customField) {
            $header[] = $customField->getId();
        }

        $header[] = "linkedContactIds";

        return $header;
    }

    /**
     * @param array<CustomField> $customFields
     * @param CustomObject $customObject
     */
    private function addCustomItemsToCsvFile(array $customFields, CustomObject $customObject): void
    {
        $offset = 0;
        $result = true;

        while($result) {
            $customItems = $this->customItemRepository
                ->getCustomItemsRelatedToProvidedCustomObject($customObject->getId(), self::CUSTOM_ITEM_LIMIT, $offset);

            if (count($customItems) == 0) {
                return;
            }

            if (count($customItems) < self::CUSTOM_ITEM_LIMIT) {
                $result = false;
            } else {
                $offset += self::CUSTOM_ITEM_LIMIT;
            }

            $listData = $this->customFieldValueModel->getItemsListData($customObject->getCustomFields(), $customItems);

            foreach ($customItems as $customItem) {
                $rowData = [];
                $rowData[] = $customItem->getId();
                $rowData[] = $customItem->getName();

                foreach ($customFields as $customField) {
                    $rowData[] = $listData->getFields($customItem->getId())[$customField->getId()]->getValue();
                }

                $fetchResult = true;
                $savedRow = $rowData;
                $contactOffset = 0;
                $customItemAdded = false;

                while($fetchResult) {
                    $results = $this->getContactIds($customItem, self::CONTACT_LIMIT, $contactOffset);

                    if (count($results) == 0 && $customItemAdded) {
                        break;
                    }

                    if(count($results) < self::CONTACT_LIMIT) {
                        $fetchResult = false;
                    } else {
                        $contactOffset += self::CONTACT_LIMIT;
                    }

                    $rowData = $savedRow;
                    $rowData[] = implode(',', $results);
                    $this->addToCsvFile($rowData);
                    $customItemAdded = true;
                }
            }
        }
    }

    /**
     * @param array<CustomField> $customFields
     */
    private function addExportFileHeaderToCsvFile(array $customFields): void
    {
        $header = $this->getCSVHeader($customFields);

        $this->addToCsvFile($header);
    }

    /**
     * @param CustomItem $customItem
     * @return array<int>
     */
    private function getContactIds(CustomItem $customItem, int $limit = 200, int $offset = 0): array
    {
        $contactIds = $this->customItemXrefContactRepository
            ->getContactIdsLinkedToCustomItem($customItem->getId(), $limit, $offset);

        return array_column($contactIds, 'contact_id');
    }

    /**
     * @param array<mixed> $data
     */
    private function addToCsvFile(array $data): void
    {
        $handler = @fopen($this->filePath, 'ab');
        fputcsv($handler, $data);
        fclose($handler);
    }

    /**
     * @param CustomItemExportScheduler $customItemExportScheduler
     * @param string $filePath
     */
    public function sendEmail(CustomItemExportScheduler $customItemExportScheduler, string $filePath): void
    {
        $user = $customItemExportScheduler->getUser();

        $message = $this->getEmailMessageWithLink($filePath);
        $this->mailHelper->setTo([$user->getEmail() => $user->getName()]);
        $this->mailHelper->setSubject(
            $this->translator->trans('custom.item.export.email_subject', ['%file_name%' => basename($filePath)])
        );
        $this->mailHelper->setBody($message);
        $this->mailHelper->parsePlainText($message);
        $this->mailHelper->send(true);
    }

    /**
     * @param string $filePath
     * @return string
     */
    public function getEmailMessageWithLink(string $filePath): string
    {
        $link = $this->customItemRouteProvider->buildExportDownloadRoute(basename($filePath));

        return $this->translator->trans(
            'custom.item.export.email',
            ['%link%' => $link, '%label%' => basename($filePath)]
        );
    }

    /**
     * @param CustomItemExportScheduler $customItemExportScheduler
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function deleteEntity(CustomItemExportScheduler $customItemExportScheduler): void
    {
//        $this->em->remove($customItemExportScheduler);
//        $this->em->flush();
    }

    /**
     * @param string $fileName
     * @return BinaryFileResponse
     */
    public function getExportFileToDownload(string $fileName): BinaryFileResponse
    {
        $filePath    = $this->coreParametersHelper->get('custom_item_export_dir').'/'.$fileName;

        return new BinaryFileResponse(
            $filePath,
            Response::HTTP_OK,
            [
                'Content-Type'        => 'application/zip',
                'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
                'Expires'             => 0,
                'Cache-Control'       => 'must-revalidate',
                'Pragma'              => 'public',
            ]
        );
    }
}
