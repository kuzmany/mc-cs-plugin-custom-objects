<?php declare(strict_types=1);

/*
* @copyright   2018 Mautic, Inc. All rights reserved
* @author      Mautic, Inc.
*
* @link        https://mautic.com
*
* @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
*/

use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemFieldListData;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemRouteProvider;

/** @var CustomItemFieldListData|null $fieldData */
$fieldData = $fieldData ?? null;

if ('index' === $tmpl) {
    $view->extend('CustomObjectsBundle:CustomItem:index.html.php');
}

$routeSelf = $view['router']->path(
    CustomItemRouteProvider::ROUTE_LIST,
    [
        'objectId'         => $customObject->getId(),
        'filterEntityId'   => $filterEntityId,
        'filterEntityType' => $filterEntityType,
        'tmpl'             => 'list',
    ]
);
?>
<?php if (count($items)): ?>
    <div class="table-responsive">
        <table class="table table-hover table-striped table-bordered" id="custom-items-<?php echo $customObject->getId(); ?>-table">
            <thead>
            <tr>
                <?php
                echo $view->render(
    'MauticCoreBundle:Helper:tableheader.html.php',
    [
                        'checkall'  => 'true',
                        'target'    => "#custom-items-{$customObject->getId()}-table",
                        'langVar'   => 'custom.item',
                        'routeBase' => 'custom_item',
                        'baseUrl'   => $routeSelf,
                    ]
);

                echo $view->render(
                    'MauticCoreBundle:Helper:tableheader.html.php',
                    [
                        'sessionVar' => 'custom.item',
                        'orderBy'    => CustomItem::TABLE_ALIAS.'.name',
                        'text'       => 'mautic.core.name',
                        'class'      => 'col-custom_item_name',
                        'baseUrl'    => $routeSelf,
                    ]
                );

                echo $view->render(
                    'MauticCoreBundle:Helper:tableheader.html.php',
                    [
                        'sessionVar' => 'custom.item',
                        'orderBy'    => CustomItem::TABLE_ALIAS.'.id',
                        'text'       => 'mautic.core.id',
                        'class'      => 'visible-md visible-lg col-asset-id',
                        'default'    => true,
                        'baseUrl'    => $routeSelf,
                    ]
                );
                ?>
                <?php if ($fieldData): ?>
                    <?php foreach ($fieldData->getColumnLabels() as $columnLabel): ?>
                        <?php echo $view->render('MauticCoreBundle:Helper:tableheader.html.php', ['text' => $columnLabel]); ?>
                    <?php endforeach; ?>
                <?endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <?php echo $view->render('MauticCoreBundle:Helper:list_actions.html.php', ['item' => $item]); ?>
                    </td>
                    <td>
                        <div>
                            <?php echo empty($filterEntityId) ? $view->render(
                    'MauticCoreBundle:Helper:publishstatus_icon.html.php',
                    [
                                    'item'  => $item,
                                    'model' => 'custom.item',
                                ]
                ) : ''; ?>
                            <a href="<?php echo $view['router']->path(CustomItemRouteProvider::ROUTE_VIEW, ['objectId' => $customObject->getId(), 'itemId' => $item->getId()]); ?>" data-toggle="ajax">
                                <?php echo $item->getName(); ?>
                            </a>
                        </div>
                    </td>
                    <td><?php echo $item->getId(); ?></td>
                    <?php if ($fieldData): ?>
                        <?php foreach ($fieldData->getFields($item->getId()) as $fieldValue): ?>
                            <td>
                                <?php echo $view->render('CustomObjectsBundle:CustomField:value.html.php', ['fieldValue'  => $fieldValue]); ?>
                            </td>
                        <?php endforeach; ?>
                    <?endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="panel-footer">
        <?php echo $view->render(
                    'MauticCoreBundle:Helper:pagination.html.php',
                    [
                'totalItems'  => $itemCount,
                'page'        => $page,
                'limit'       => $limit,
                'baseUrl'     => $routeSelf = $view['router']->path(CustomItemRouteProvider::ROUTE_LIST, ['objectId'  => $customObject->getId()]),
                'queryString' => "&filterEntityId={$filterEntityId}&filterEntityType={$filterEntityType}",
                'sessionVar'  => 'custom.item',
                'routeBase'   => CustomItemRouteProvider::ROUTE_LIST,
            ]
                ); ?>
    </div>
<?php else: ?>
    <?php echo $view->render('MauticCoreBundle:Helper:noresults.html.php', ['tip' => 'custom.object.noresults.tip']); ?>
<?php endif; ?>
