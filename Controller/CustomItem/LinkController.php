<?php

declare(strict_types=1);

/*
 * @copyright   2018 Mautic, Inc. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.com
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\CustomObjectsBundle\Controller\CustomItem;

use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\JsonResponse;
use MauticPlugin\CustomObjectsBundle\Controller\JsonController;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use UnexpectedValueException;
use Mautic\CoreBundle\Service\FlashBag;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemXrefContactModel;

class LinkController extends JsonController
{
    /**
     * @var CustomItemModel
     */
    private $customItemModel;

    /**
     * @var CustomItemXrefContactModel
     */
    private $customItemXrefContactModel;

    /**
     * @var CustomItemPermissionProvider
     */
    private $permissionProvider;

    /**
     * @var FlashBag
     */
    private $flashBag;

    /**
     * @param CustomItemModel              $customItemModel
     * @param CustomItemXrefContactModel   $customItemXrefContactModel
     * @param CustomItemPermissionProvider $permissionProvider
     * @param FlashBag                     $flashBag
     */
    public function __construct(
        CustomItemModel $customItemModel,
        CustomItemXrefContactModel $customItemXrefContactModel,
        CustomItemPermissionProvider $permissionProvider,
        FlashBag $flashBag
    ) {
        $this->customItemModel            = $customItemModel;
        $this->customItemXrefContactModel = $customItemXrefContactModel;
        $this->permissionProvider         = $permissionProvider;
        $this->flashBag                   = $flashBag;
    }

    /**
     * @param int    $itemId
     * @param string $entityType
     * @param int    $entityId
     *
     * @return JsonResponse
     */
    public function saveAction(int $itemId, string $entityType, int $entityId): JsonResponse
    {
        try {
            $customItem = $this->customItemModel->fetchEntity($itemId);
            $this->permissionProvider->canEdit($customItem);
            $this->makeLinkBasedOnEntityType($itemId, $entityType, $entityId);
        } catch (UniqueConstraintViolationException $e) {
            $this->flashBag->add(
                'custom.item.error.link.exists.already',
                ['%itemId%' => $itemId, '%entityType%' => $entityType, '%entityId%' => $entityId],
                FlashBag::LEVEL_ERROR
            );
        } catch (ForbiddenException | NotFoundException | UnexpectedValueException $e) {
            $this->flashBag->add($e->getMessage(), [], FlashBag::LEVEL_ERROR);
        }

        return $this->renderJson();
    }

    /**
     * @param int    $itemId
     * @param string $entityType
     * @param int    $entityId
     *
     * @throws UnexpectedValueException
     * @throws UniqueConstraintViolationException
     */
    private function makeLinkBasedOnEntityType(int $itemId, string $entityType, int $entityId): void
    {
        switch ($entityType) {
            case 'contact':
                $this->customItemXrefContactModel->linkContact($itemId, $entityId);

                break;
            default:
                throw new UnexpectedValueException("Entity {$entityType} cannot be linked to a custom item");

                break;
        }
    }
}
