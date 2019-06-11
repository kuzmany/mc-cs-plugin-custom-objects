<?php

declare(strict_types=1);

/*
 * @copyright   2019 Mautic, Inc. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.com
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\CustomObjectsBundle\EventListener;

use Mautic\CoreBundle\EventListener\CommonSubscriber;
use MauticPlugin\CustomObjectsBundle\Provider\ConfigProvider;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use Mautic\ApiBundle\ApiEvents;
use Mautic\ApiBundle\Event\ApiEntityEvent;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use Mautic\LeadBundle\Entity\Lead;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use Symfony\Component\HttpFoundation\Response;

class ApiSubscriber extends CommonSubscriber
{
    /**
     * @var ConfigProvider
     */
    private $configProvider;

    /**
     * @var CustomObjectModel
     */
    private $customObjectModel;

    /**
     * @var CustomItemModel
     */
    private $customItemModel;

    /**
     * @param ConfigProvider    $configProvider
     * @param CustomObjectModel $customObjectModel
     * @param CustomItemModel   $customItemModel
     */
    public function __construct(
        ConfigProvider $configProvider,
        CustomObjectModel $customObjectModel,
        CustomItemModel $customItemModel
    ) {
        $this->configProvider    = $configProvider;
        $this->customObjectModel = $customObjectModel;
        $this->customItemModel   = $customItemModel;
    }

    /**
     * @return mixed[]
     */
    public static function getSubscribedEvents(): array
    {
        // This check can be removed once https://github.com/mautic-inc/mautic-cloud/pull/555 is merged to deployed.
        if (defined('\Mautic\ApiBundle\ApiEvents::API_ON_ENTITY_PRE_SAVE')) {
            return [
                ApiEvents::API_ON_ENTITY_PRE_SAVE  => 'validateCustomObjectsInContactRequest',
                ApiEvents::API_ON_ENTITY_POST_SAVE => 'saveCustomObjectsInContactRequest',
            ];
        }

        return [];
    }

    /**
     * @param ApiEntityEvent $event
     */
    public function validateCustomObjectsInContactRequest(ApiEntityEvent $event): void
    {
        $this->saveCustomItems($event, true);
    }

    /**
     * @param ApiEntityEvent $event
     */
    public function saveCustomObjectsInContactRequest(ApiEntityEvent $event): void
    {
        $this->saveCustomItems($event);
    }

    /**
     * @param ApiEntityEvent $event
     * @param bool           $dryRun
     */
    private function saveCustomItems(ApiEntityEvent $event, bool $dryRun = false): void
    {
        try {
            $customObjects = $this->getCustomObjectsFromContactCreateRequest(
                $event->getEntityRequestParameters(),
                $event->getRequest()
            );
        } catch (InvalidArgumentException $e) {
            return;
        }

        /** @var Lead $contact */
        $contact = $event->getEntity();

        foreach ($customObjects as $customObjectAlias => $customObjectData) {
            if (empty($customObjectData['data']) || !is_array($customObjectData['data'])) {
                continue;
            }

            $customObject = $this->getCustomObject($customObjectAlias);

            foreach ($customObjectData['data'] as $customItemData) {
                $customItem = $this->getCustomItem($customObject, $customItemData);
                $customItem = $this->populateCustomItem($customItem, $customItemData);

                $this->customItemModel->save($customItem, $dryRun);

                if (!$dryRun) {
                    $this->customItemModel->linkEntity($customItem, 'contact', (int) $contact->getId());
                }
            }
        }
    }

    /**
     * @param Request $request
     * @param mixed[] $entityRequestParameters
     *
     * @return mixed[]
     *
     * @throws InvalidArgumentException
     */
    private function getCustomObjectsFromContactCreateRequest(array $entityRequestParameters, Request $request): array
    {
        if (!$this->configProvider->pluginIsEnabled()) {
            throw new InvalidArgumentException('Custom Object Plugin is disabled');
        }

        if (1 !== preg_match('/^\/api\/contacts\/.*(new|edit)/', $request->getPathInfo())) {
            throw new InvalidArgumentException('Not a API request we care about');
        }

        if (empty($entityRequestParameters['customObjects']) || !is_array($entityRequestParameters['customObjects'])) {
            throw new InvalidArgumentException('The request payload does not contain any custom items in the customObjects attribute.');
        }

        return $entityRequestParameters['customObjects'];
    }

    /**
     * @param string $customObjectAlias
     *
     * @return CustomObject
     *
     * @throws NotFoundException
     */
    private function getCustomObject(string $customObjectAlias): CustomObject
    {
        try {
            return $this->customObjectModel->fetchEntityByAlias($customObjectAlias);
        } catch (NotFoundException $e) {
            throw new NotFoundException($e->getMessage(), Response::HTTP_BAD_REQUEST, $e);
        }
    }

    /**
     * @param CustomObject $customObject
     * @param mixed[]      $customItemData
     *
     * @return CustomItem
     */
    private function getCustomItem(CustomObject $customObject, array $customItemData): CustomItem
    {
        if (empty($customItemData['id'])) {
            return new CustomItem($customObject);
        }

        try {
            return $this->customItemModel->fetchEntity((int) $customItemData['id']);
        } catch (NotFoundException $e) {
            throw new NotFoundException($e->getMessage(), Response::HTTP_BAD_REQUEST, $e);
        }
    }

    /**
     * @param CustomItem $customItem
     * @param mixed[]    $customItemData
     *
     * @return CustomItem
     */
    private function populateCustomItem(CustomItem $customItem, array $customItemData): CustomItem
    {
        if (!empty($customItemData['name'])) {
            $customItem->setName($customItemData['name']);
        }

        if (!empty($customItemData['attributes']) && is_array($customItemData['attributes'])) {
            foreach ($customItemData['attributes'] as $fieldAlias => $value) {
                try {
                    $customFieldValue = $customItem->findCustomFieldValueForFieldAlias($fieldAlias);
                    $customFieldValue->setValue($value);
                } catch (NotFoundException $e) {
                    $customItem->createNewCustomFieldValueByFieldAlias($fieldAlias, $value);
                }
            }
        }

        return $customItem;
    }
}
