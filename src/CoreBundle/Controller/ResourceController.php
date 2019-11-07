<?php
/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\Controller;

use APY\DataGridBundle\Grid\Action\MassAction;
use APY\DataGridBundle\Grid\Action\RowAction;
use APY\DataGridBundle\Grid\Export\CSVExport;
use APY\DataGridBundle\Grid\Export\ExcelExport;
use APY\DataGridBundle\Grid\Grid;
use APY\DataGridBundle\Grid\Row;
use APY\DataGridBundle\Grid\Source\Entity;
use Chamilo\CoreBundle\Block\BreadcrumbBlockService;
use Chamilo\CoreBundle\Component\Utils\Glide;
use Chamilo\CoreBundle\Entity\Resource\ResourceLink;
use Chamilo\CoreBundle\Entity\Resource\ResourceNode;
use Chamilo\CoreBundle\Security\Authorization\Voter\ResourceNodeVoter;
use Chamilo\CourseBundle\Controller\CourseControllerInterface;
use Chamilo\CourseBundle\Controller\CourseControllerTrait;
use Chamilo\CourseBundle\Entity\CDocument;
use Chamilo\CourseBundle\Repository\CDocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\QueryBuilder;
use FOS\RestBundle\View\View;
use League\Flysystem\Filesystem;
use Oneup\UploaderBundle\Uploader\Response\EmptyResponse;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Resource\Exception\UpdateHandlingException;
use Sylius\Component\Resource\ResourceActions;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\File\Exception\UploadException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Vich\UploaderBundle\Util\Transliterator;
use ZipStream\Option\Archive;
use ZipStream\ZipStream;

/**
 * Class ResourceController.
 *
 * @Route("/resources")
 *
 * @author Julio Montoya <gugli100@gmail.com>.
 */
class ResourceController extends AbstractResourceController implements CourseControllerInterface
{
    use CourseControllerTrait;

    /**
     * @Route("/{tool}/{type}", name="chamilo_core_resource_index")
     *
     * Example: /document/files
     * For the tool value check the Tool entity.
     * For the type value check the ResourceType entity.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function indexAction(Request $request, Grid $grid): Response
    {
        $tool = $request->get('tool');
        $type = $request->get('type');

        $grid = $this->getGrid( $request, $grid);

        $breadcrumb = $this->breadcrumbBlockService;
        $breadcrumb->addChild(
            $this->translator->trans('Documents'),
            [
                'uri' => '#',
            ]
        );

        $id = $this->getCourse()->getResourceNode()->getId();

        return $grid->getGridResponse(
            '@ChamiloTheme/Resource/index.html.twig',
            ['tool' => $tool, 'type' => $type, 'id' => $id]
        );
    }

    /**
     * @param Request $request
     * @param Grid    $grid
     * @param int     $resourceNodeId
     *
     * @return Grid
     */
    public function getGrid(Request $request, Grid $grid, $resourceNodeId = 0)
    {
        $tool = $request->get('tool');
        $type = $request->get('type');

        $repository = $this->getRepository($tool, $type);
        $class = $repository->getRepository()->getClassName();
        $source = new Entity($class);

        /*$tableAlias = $source->getTableAlias();
        $source->manipulateQuery(function (QueryBuilder $query) use ($tableAlias, $course) {
                $query->andWhere($tableAlias . '.cId = '.$course->getId());
                //$query->resetDQLPart('orderBy');
            }
        );*/

        $course = $this->getCourse();
        $session = $this->getSession();

        $parent = $course->getResourceNode();
        if (!empty($resourceNodeId)) {
            $parent = $repository->getResourceNodeRepository()->find($resourceNodeId);
        }

        $qb = $repository->getResourcesByCourse($course, $session, null, $parent);

        // 3. Set QueryBuilder to the source.
        $source->initQueryBuilder($qb);
        $grid->setSource($source);

        $title = $grid->getColumn('title');
        $title->setSafe(false);

        //$grid->hideFilters();
        $grid->setLimits(20);
        //$grid->isReadyForRedirect();
        //$grid->setMaxResults(1);
        //$grid->setLimits(2);

        $translation = $this->translator;
        $courseIdentifier = $course->getCode();

        $routeParams = ['tool' => $tool, 'type' => $type, 'cidReq' => $courseIdentifier, 'id'];

        $grid->getColumn('title')->manipulateRenderCell(
            function ($value, Row $row, $router) use ($routeParams) {
                /** @var CDocument $entity */
                $entity = $row->getEntity();
                $resourceNode = $entity->getResourceNode();
                $id = $resourceNode->getId();

                $myParams = $routeParams;
                $myParams['id'] = $id;
                unset($myParams[0]);
                if ($resourceNode->hasResourceFile()) {
                    $url = $router->generate(
                        'chamilo_core_resource_show',
                        $myParams
                    );
                } else {
                    $url = $router->generate(
                        'chamilo_core_resource_list',
                        $myParams
                    );
                }

                return '<a href="'.$url.'">'.$value.'</a>';
            }
        );

        if ($this->isGranted(ResourceNodeVoter::ROLE_CURRENT_COURSE_TEACHER)) {
            $deleteMassAction = new MassAction(
                'Delete',
                'chamilo.controller.notebook:deleteMassAction',
                true,
                ['course' => $courseIdentifier]
            );
            $grid->addMassAction($deleteMassAction);
        }

        // Show resource data
        $myRowAction = new RowAction(
            $translation->trans('View'),
            'chamilo_core_resource_show',
            false,
            '_self',
            ['class' => 'btn btn-secondary']
        );
        $myRowAction->setRouteParameters($routeParams);

        $setNodeParameters = function (RowAction $action, Row $row) use ($routeParams) {
            $id = $row->getEntity()->getResourceNode()->getId();
            $routeParams['id'] = $id;
            $action->setRouteParameters($routeParams);
            return $action;
        };
        $myRowAction->addManipulateRender($setNodeParameters);

        $grid->addRowAction($myRowAction);

        if ($this->isGranted(ResourceNodeVoter::ROLE_CURRENT_COURSE_TEACHER)) {
            // Edit
            $myRowAction = new RowAction(
                $translation->trans('Edit'),
                'chamilo_core_resource_edit',
                false,
                '_self',
                ['class' => 'btn btn-secondary']
            );
            $myRowAction->setRouteParameters($routeParams);
            $myRowAction->addManipulateRender($setNodeParameters);

            $grid->addRowAction($myRowAction);

            // Delete
            $myRowAction = new RowAction(
                $translation->trans('Delete'),
                'chamilo_core_resource_delete',
                false,
                '_self',
                ['class' => 'btn btn-danger', 'form_delete' => true]
            );
            $myRowAction->setRouteParameters($routeParams);
            $myRowAction->addManipulateRender($setNodeParameters);
            $grid->addRowAction($myRowAction);
        }

        /*$grid->addExport(new CSVExport($translation->trans('CSV export'), 'export', ['course' => $courseIdentifier]));
        $grid->addExport(
            new ExcelExport(
                $translation->trans('Excel export'),
                'export',
                ['course' => $courseIdentifier]
            )
        );*/

        return $grid;
    }

    /**
     * @param Request $request
     */
    public function setBreadCrumb(Request $request)
    {
        $tool = $request->get('tool');
        $type = $request->get('type');
        $resourceNodeId = $request->get('id');
        $courseCode = $request->get('cidReq');

        if (!empty($resourceNodeId)) {
            $breadcrumb = $this->breadcrumbBlockService;

            $breadcrumb->addChild(
                $this->translator->trans('Documents'),
                [
                    'uri' => $this->generateUrl(
                        'chamilo_core_resource_index',
                        ['tool' => $tool, 'type' => $type, 'cidReq' => $courseCode]
                    ),
                ]
            );

            /** @var ResourceNode $parent */
            $parent = $originalParent = $this->getRepository($tool, $type)->getResourceNodeRepository()->find($resourceNodeId);

            $parentList = [];
            while ($parent !== null) {
                if ($type !== $parent->getResourceType()->getName()){
                    break;
                }
                $parent = $parent->getParent();
                if ($parent) {
                    $parent = $this->getRepository($tool, $type)->getResourceNodeRepository()->find($parent->getId());
                    $parentList[] = $parent;
                }
            }

            $parentList = array_reverse($parentList);

            foreach ($parentList as $parent) {
                $breadcrumb->addChild(
                    $parent->getName(),
                    [
                        'uri' => $this->generateUrl(
                            'chamilo_core_resource_list',
                            ['tool' => $tool, 'type' => $type, 'id' => $parent->getId(), 'cidReq' => $courseCode]
                        ),
                    ]
                );
            }

            $breadcrumb->addChild(
                $originalParent->getName(),
                [
                    'uri' => $this->generateUrl(
                        'chamilo_core_resource_list',
                        ['tool' => $tool, 'type' => $type, 'id' => $originalParent->getId(), 'cidReq' => $courseCode]
                    ),
                ]
            );
        }
    }

    /**
     * @Route("/{tool}/{type}/{id}/list", name="chamilo_core_resource_list")
     *
     * If node has children show it
     *
     * @param Request $request
     *
     * @return Response
     */
    public function listAction(Request $request, Grid $grid): Response
    {
        $tool = $request->get('tool');
        $type = $request->get('type');
        $resourceNodeId = $request->get('id');

        $grid = $this->getGrid( $request, $grid,$resourceNodeId);

        // Set breadcrumb
        $this->setBreadCrumb($request);

        return $grid->getGridResponse(
            '@ChamiloTheme/Resource/index.html.twig',
            ['parent_id' => $resourceNodeId, 'tool' => $tool, 'type' => $type, 'id' => $resourceNodeId]
        );
    }

    /**
     * @Route("/{tool}/{type}/{id}/new_folder", methods={"GET", "POST"}, name="chamilo_core_resource_new_folder")
     *
     * @param Request $request
     *
     * @return Response
     */
    public function newFolderAction(Request $request): Response
    {
        $this->setBreadCrumb($request);

        return $this->createResource($request, 'folder');
    }

    /**
     * @Route("/{tool}/{type}/{id}/new", methods={"GET", "POST"}, name="chamilo_core_resource_new")
     *
     * @param Request $request
     *
     * @return Response
     */
    public function newAction(Request $request): Response
    {
        $this->setBreadCrumb($request);

        return $this->createResource($request, 'file');
    }

    /**
     * Shows a resource.
     *
     * @Route("/{tool}/{type}/{id}/show", methods={"GET"}, name="chamilo_core_resource_show")
     *
     * @param Request $request
     *
     * @return Response
     */
    public function showAction(Request $request): Response
    {
        $this->setBreadCrumb($request);

        $em = $this->getDoctrine();

        $id = $request->get('id');
        $resourceNode = $em->getRepository('ChamiloCoreBundle:Resource\ResourceNode')->find($id);

        if (null === $resourceNode) {
            throw new NotFoundHttpException();
        }

        $this->denyAccessUnlessGranted(
            ResourceNodeVoter::VIEW,
            $resourceNode,
            'Unauthorised access to resource'
        );

        $tool = $request->get('tool');
        $type = $request->get('type');

        $params = [
            'resource_node' => $resourceNode,
            'tool' => $tool,
            'type' => $type,
        ];

        return $this->render('@ChamiloTheme/Resource/show.html.twig', $params);
    }

    /**
     * @Route("/{tool}/{type}/{id}/edit", methods={"GET", "POST"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function editAction(Request $request): Response
    {
        $configuration = $this->requestConfigurationFactory->create($this->metadata, $request);

        $this->isGrantedOr403($configuration, ResourceActions::UPDATE);
        /** @var CDocument $resource */
        $resource = $this->findOr404($configuration);
        $resourceNode = $resource->getResourceNode();

        $this->denyAccessUnlessGranted(
            ResourceNodeVoter::EDIT,
            $resourceNode,
            'Unauthorised access to resource'
        );

        $form = $this->resourceFormFactory->create($configuration, $resource);

        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true) && $form->handleRequest($request)->isValid()) {
            $resource = $form->getData();

            /** @var ResourceControllerEvent $event */
            $event = $this->eventDispatcher->dispatchPreEvent(ResourceActions::UPDATE, $configuration, $resource);

            if ($event->isStopped() && !$configuration->isHtmlRequest()) {
                throw new HttpException($event->getErrorCode(), $event->getMessage());
            }
            if ($event->isStopped()) {
                $this->flashHelper->addFlashFromEvent($configuration, $event);

                if ($event->hasResponse()) {
                    return $event->getResponse();
                }

                return $this->redirectHandler->redirectToResource($configuration, $resource);
            }

            try {
                $this->resourceUpdateHandler->handle($resource, $configuration, $this->manager);
            } catch (UpdateHandlingException $exception) {
                if (!$configuration->isHtmlRequest()) {
                    return $this->viewHandler->handle(
                        $configuration,
                        View::create($form, $exception->getApiResponseCode())
                    );
                }

                $this->flashHelper->addErrorFlash($configuration, $exception->getFlash());

                return $this->redirectHandler->redirectToReferer($configuration);
            }

            $postEvent = $this->eventDispatcher->dispatchPostEvent(ResourceActions::UPDATE, $configuration, $resource);

            if (!$configuration->isHtmlRequest()) {
                $view = $configuration->getParameters()->get('return_content', false) ? View::create(
                    $resource,
                    Response::HTTP_OK
                ) : View::create(null, Response::HTTP_NO_CONTENT);

                return $this->viewHandler->handle($configuration, $view);
            }

            $this->flashHelper->addSuccessFlash($configuration, ResourceActions::UPDATE, $resource);

            if ($postEvent->hasResponse()) {
                return $postEvent->getResponse();
            }

            return $this->redirectHandler->redirectToResource($configuration, $resource);
        }

        if (!$configuration->isHtmlRequest()) {
            return $this->viewHandler->handle($configuration, View::create($form, Response::HTTP_BAD_REQUEST));
        }

        $initializeEvent = $this->eventDispatcher->dispatchInitializeEvent(ResourceActions::UPDATE, $configuration, $resource);
        if ($initializeEvent->hasResponse()) {
            return $initializeEvent->getResponse();
        }

        $view = View::create()
            ->setData([
                'configuration' => $configuration,
                'metadata' => $this->metadata,
                'resource' => $resource,
                $this->metadata->getName() => $resource,
                'form' => $form->createView(),
            ])
            ->setTemplate($configuration->getTemplate(ResourceActions::UPDATE.'.html'))
        ;

        return $this->viewHandler->handle($configuration, $view);
    }

    /**
     * @Route("/{tool}/{type}/{id}", methods={"DELETE"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function deleteAction(Request $request): Response
    {
        $configuration = $this->requestConfigurationFactory->create($this->metadata, $request);

        $this->isGrantedOr403($configuration, ResourceActions::UPDATE);
        /** @var CDocument $resource */
        $resource = $this->findOr404($configuration);
        $resourceNode = $resource->getResourceNode();

        $this->denyAccessUnlessGranted(
            ResourceNodeVoter::EDIT,
            $resourceNode,
            'Unauthorised access to resource'
        );

        $form = $this->resourceFormFactory->create($configuration, $resource);

        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true) && $form->handleRequest($request)->isValid()) {
            $resource = $form->getData();

            /** @var ResourceControllerEvent $event */
            $event = $this->eventDispatcher->dispatchPreEvent(ResourceActions::UPDATE, $configuration, $resource);

            if ($event->isStopped() && !$configuration->isHtmlRequest()) {
                throw new HttpException($event->getErrorCode(), $event->getMessage());
            }
            if ($event->isStopped()) {
                $this->flashHelper->addFlashFromEvent($configuration, $event);

                if ($event->hasResponse()) {
                    return $event->getResponse();
                }

                return $this->redirectHandler->redirectToResource($configuration, $resource);
            }

            try {
                $this->resourceUpdateHandler->handle($resource, $configuration, $this->manager);
            } catch (UpdateHandlingException $exception) {
                if (!$configuration->isHtmlRequest()) {
                    return $this->viewHandler->handle(
                        $configuration,
                        View::create($form, $exception->getApiResponseCode())
                    );
                }

                $this->flashHelper->addErrorFlash($configuration, $exception->getFlash());

                return $this->redirectHandler->redirectToReferer($configuration);
            }

            $postEvent = $this->eventDispatcher->dispatchPostEvent(ResourceActions::UPDATE, $configuration, $resource);

            if (!$configuration->isHtmlRequest()) {
                $view = $configuration->getParameters()->get('return_content', false) ? View::create($resource, Response::HTTP_OK) : View::create(null, Response::HTTP_NO_CONTENT);

                return $this->viewHandler->handle($configuration, $view);
            }

            $this->flashHelper->addSuccessFlash($configuration, ResourceActions::UPDATE, $resource);

            if ($postEvent->hasResponse()) {
                return $postEvent->getResponse();
            }

            return $this->redirectHandler->redirectToResource($configuration, $resource);
        }

        if (!$configuration->isHtmlRequest()) {
            return $this->viewHandler->handle($configuration, View::create($form, Response::HTTP_BAD_REQUEST));
        }

        $initializeEvent = $this->eventDispatcher->dispatchInitializeEvent(ResourceActions::UPDATE, $configuration, $resource);
        if ($initializeEvent->hasResponse()) {
            return $initializeEvent->getResponse();
        }

        $view = View::create()
            ->setData([
                'configuration' => $configuration,
                'metadata' => $this->metadata,
                'resource' => $resource,
                $this->metadata->getName() => $resource,
                'form' => $form->createView(),
            ])
            ->setTemplate($configuration->getTemplate(ResourceActions::UPDATE.'.html'))
        ;

        return $this->viewHandler->handle($configuration, $view);
    }

    /**
     * @Route("/{tool}/{type}/{id}/file", methods={"GET"}, name="chamilo_core_resource_file")
     *
     * @param Request $request
     * @param Glide   $glide
     *
     * @return Response
     */
    public function getResourceFileAction(Request $request, Glide $glide): Response
    {
        $id = $request->get('id');
        $filter = $request->get('filter');
        $em = $this->getDoctrine();
        $resourceNode = $em->getRepository('ChamiloCoreBundle:Resource\ResourceNode')->find($id);

        if ($resourceNode === null) {
            throw new FileNotFoundException('Not found');
        }

        return $this->showFile($request, $resourceNode, $glide, 'show', $filter);
    }

    /**
     * Gets a document when calling route resources_document_get_file.
     *
     * @param Request             $request
     * @param CDocumentRepository $documentRepo
     * @param Glide               $glide
     *
     * @return Response
     * @throws \League\Flysystem\FileNotFoundException
     */
    public function getDocumentAction(Request $request, CDocumentRepository $documentRepo, Glide $glide): Response
    {
        $file = $request->get('file');
        $type = $request->get('type');
        // see list of filters in config/services.yaml
        $filter = $request->get('filter');
        $type = !empty($type) ? $type : 'show';
        $criteria = [
            'path' => "/$file",
            'course' => $this->getCourse(),
        ];

        $document = $documentRepo->findOneBy($criteria);

        if (null === $document) {
            throw new NotFoundHttpException();
        }
        /** @var ResourceNode $resourceNode */
        $resourceNode = $document->getResourceNode();

        return $this->showFile($request, $resourceNode, $glide, $type, $filter);
    }

    /**
     * Downloads a folder.
     *
     * @param Request             $request
     * @param CDocumentRepository $documentRepo
     *
     * @return Response
     */
    public function downloadFolderAction(Request $request, CDocumentRepository $documentRepo)
    {
        $folderId = (int) $request->get('folderId');
        $courseNode = $this->getCourse()->getResourceNode();

        if (empty($folderId)) {
            $resourceNode = $courseNode;
        } else {
            $document = $documentRepo->find($folderId);
            $resourceNode = $document->getResourceNode();
        }

        $type = $documentRepo->getResourceType();

        if (null === $resourceNode || null === $courseNode) {
            throw new NotFoundHttpException();
        }

        $this->denyAccessUnlessGranted(
            ResourceNodeVoter::VIEW,
            $resourceNode,
            'Unauthorised access to resource'
        );

        $zipName = $resourceNode->getName().'.zip';
        $rootNodePath = $resourceNode->getPathForDisplay();

        /** @var Filesystem $fileSystem */
        $fileSystem = $this->get('oneup_flysystem.resources_filesystem');

        $resourceNodeRepo = $documentRepo->getResourceNodeRepository();

        $criteria = Criteria::create()
            ->where(Criteria::expr()->neq('resourceFile', null))
            ->andWhere(Criteria::expr()->eq('resourceType', $type))
        ;

        /** @var ArrayCollection|ResourceNode[] $children */
        /** @var QueryBuilder $children */
        $qb = $resourceNodeRepo->getChildrenQueryBuilder($resourceNode);
        $qb->addCriteria($criteria);
        $children = $qb->getQuery()->getResult();

        /** @var ResourceNode $node */
        foreach ($children as $node) {
            /*if ($node->hasResourceFile()) {
                $resourceFile = $node->getResourceFile();
                $systemName = $resourceFile->getFile()->getPathname();
                $stream = $fileSystem->readStream($systemName);
                //error_log($node->getPathForDisplay());
                $fileToDisplay = str_replace($rootNodePath, '', $node->getPathForDisplay());
                var_dump($fileToDisplay);
            }*/
            var_dump($node->getPathForDisplay());
            //var_dump($node['path']);
        }

        exit;


        $response = new StreamedResponse(function() use($rootNodePath, $zipName, $children, $fileSystem)
        {
            // Define suitable options for ZipStream Archive.
            $options = new Archive();
            $options->setContentType('application/octet-stream');
            //initialise zipstream with output zip filename and options.
            $zip = new ZipStream($zipName, $options);

            /** @var ResourceNode $node */
            foreach ($children as $node) {
                $resourceFile = $node->getResourceFile();
                $systemName = $resourceFile->getFile()->getPathname();
                $stream = $fileSystem->readStream($systemName);
                //error_log($node->getPathForDisplay());
                $fileToDisplay = str_replace($rootNodePath, '', $node->getPathForDisplay());
                error_log($fileToDisplay);
                $zip->addFileFromStream($fileToDisplay, $stream);
            }
            //$data = $repo->getDocumentContent($not_deleted_file['id']);
            //$zip->addFile($not_deleted_file['path'], $data);
            $zip->finish();
        });

        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            Transliterator::transliterate($zipName)
        );
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Type', 'application/octet-stream');

        return $response;
    }

    /**
     * Upload form.
     *
     * @Route("/{tool}/{type}/{id}/upload", name="chamilo_core_resource_upload", methods={"GET", "POST"}, options={"expose"=true})
     */
    public function uploadAction(Request $request, $tool, $type, $id): Response
    {
        $this->setBreadCrumb( $request);
        //$helper = $this->container->get('oneup_uploader.templating.uploader_helper');
        //$endpoint = $helper->endpoint('courses');
        $session = $this->getSession();
        $sessionId = $session ? $session->getId() : 0;

        return $this->render(
            '@ChamiloTheme/Resource/upload.html.twig',
            [
                'id' => $id,
                'type' => $type,
                'tool' => $tool,
                'cidReq' => $this->getCourse()->getCode(),
                'id_session' => $sessionId,
            ]
        );
    }

    /**
     * @return JsonResponse
     */
    public function upload()
    {
        error_log('upload!!!');
        return;
        $request = $this->getRequest();
        $response = new EmptyResponse();
        $files = $this->getFiles($request->files);

        $chunked = null !== $request->headers->get('content-range');

        try {
            /** @var UploadedFile $file */
            foreach ($files as $file) {
                try {
                    $file->getFilename();
                    $type = $request->get('type');

                    if ($type === 'course') {
                        $courseCode = $request->get('identifier');
                        $this->container->get('');
                    }

                    $chunked ?
                        $this->handleChunkedUpload($file, $response, $request) :
                        $this->handleUpload($file, $response, $request);
                } catch (UploadException $e) {
                    $this->errorHandler->addException($response, $e);
                }
            }
        } catch (UploadException $e) {
            // return nothing
            return new JsonResponse([]);
        }

        return $this->createSupportedJsonResponse($response->assemble());
    }


    /**
     * @param Request      $request
     * @param ResourceNode $resourceNode
     * @param Glide        $glide
     * @param              $type
     * @param string       $filter
     *
     * @return mixed|StreamedResponse
     */
    private function showFile(Request $request, ResourceNode $resourceNode, Glide $glide, $type, $filter = '')
    {
        $this->denyAccessUnlessGranted(
            ResourceNodeVoter::VIEW,
            $resourceNode,
            'Unauthorised access to resource'
        );
        $resourceFile = $resourceNode->getResourceFile();

        if (!$resourceFile) {
            throw new NotFoundHttpException();
        }

        $fileName = $resourceNode->getName();
        $filePath = $resourceFile->getFile()->getPathname();
        $mimeType = $resourceFile->getMimeType();

        switch ($type) {
            case 'download':
                $forceDownload = true;
                break;
            case 'show':
            default:
                $forceDownload = false;
                // If it's an image then send it to Glide.
                if (strpos($mimeType, 'image') !== false) {
                    $server = $glide->getServer();
                    $params = $request->query->all();

                    // The filter overwrites the params from get
                    if (!empty($filter)) {
                        $params = $glide->getFilters()[$filter] ?? [];
                    }

                    // The image was cropped manually by the user, so we force to render this version,
                    // no matter other crop parameters.
                    $crop = $resourceFile->getCrop();
                    if (!empty($crop)) {
                        $params['crop'] = $crop;
                    }

                    return $server->getImageResponse($filePath, $params);
                }
                break;
        }

        $stream = $this->fs->readStream($filePath);
        $response = new StreamedResponse(function () use ($stream): void {
            stream_copy_to_stream($stream, fopen('php://output', 'wb'));
        });
        $disposition = $response->headers->makeDisposition(
            $forceDownload ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            Transliterator::transliterate($fileName)
        );
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Type', $mimeType ?: 'application/octet-stream');

        return $response;
    }


    /**
     * @param string $fileType
     *
     * @return RedirectResponse|Response|null
     */
    private function createResource(Request $request, $fileType = 'file')
    {
        $tool = $request->get('tool');
        $type = $request->get('type');
        $resourceNodeParentId = $request->get('id');

        $repository = $this->getRepositoryFromRequest($request);

        /*$configuration = $this->requestConfigurationFactory->create($this->metadata, $request);
        $this->isGrantedOr403($configuration, ResourceActions::CREATE);
        /** @var CDocument $newResource */

        $form = $repository->getForm($this->container->get('form.factory'));

        $course = $this->getCourse();
        $session = $this->getSession();
        $parent = $course;
        if (!empty($resourceNodeParentId)) {
            $parent = $repository->getRepository()->findOneBy(['resourceNode' => $resourceNodeParentId]);
        }

        if ($request->isMethod('POST') && $form->handleRequest($request)->isValid()) {
            /** @var CDocument $newResource */
            $newResource = $form->getData();
            $path = \URLify::filter($newResource->getTitle());
            switch ($fileType) {
                case 'folder':
                    $newResource
                        ->setPath($path)
                        ->setSize(0)
                    ;
                    break;
                case 'file':
                    $newResource
                        ->setPath($path)
                        ->setSize(0)
                    ;
                    break;
            }

            $newResource
                ->setCourse($course)
                ->setSession($session)
                ->setFiletype($fileType)
                //->setTitle($title) // already added in $form->getData()
                //->setComment($comment)
                ->setReadonly(false)
            ;
            $em = $this->getDoctrine()->getManager();
            $em->persist($newResource);
            $newResource->setId($newResource->getIid());
            $em->persist($newResource);
            $resourceNode = $repository->addResourceNode($newResource, $this->getUser(), $parent);

            $repository->addResourceNodeToCourse(
                $resourceNode,
                ResourceLink::VISIBILITY_PUBLISHED,
                $course,
                $session,
                null
            );

            // Loops all sharing options
            /*foreach ($shareList as $share) {
                $idList = [];
                if (isset($share['search'])) {
                    $idList = explode(',', $share['search']);
                }

                $resourceRight = null;
                if (isset($share['mask'])) {
                    $resourceRight = new ResourceRight();
                    $resourceRight
                        ->setMask($share['mask'])
                        ->setRole($share['role'])
                    ;
                }

                // Build links
                switch ($share['sharing']) {
                    case 'everyone':
                        $repository->addResourceToEveryone(
                            $resourceNode,
                            $resourceRight
                        );
                        break;
                    case 'course':
                        $repository->addResourceToCourse(
                            $resourceNode,
                            $course,
                            $resourceRight
                        );
                        break;
                    case 'session':
                        $repository->addResourceToSession(
                            $resourceNode,
                            $course,
                            $session,
                            $resourceRight
                        );
                        break;
                    case 'user':
                        // Only for me
                        if (isset($share['only_me'])) {
                            $repository->addResourceOnlyToMe($resourceNode);
                        } else {
                            // To other users
                            $repository->addResourceToUserList($resourceNode, $idList);
                        }
                        break;
                    case 'group':
                        // @todo
                        break;
                }*/
            //}

            $em->flush();
            $this->addFlash('success', 'saved');

            return $this->redirectToRoute(
                'chamilo_core_resource_list',
                [
                    'id' => $resourceNodeParentId,
                    'tool' => $tool,
                    'type' => $type,
                    'cidReq' => $this->getCourse()->getCode()
                ]
            );
        }

        switch ($fileType) {
            case 'folder':
                $template = '@ChamiloTheme/Resource/new_folder.html.twig';
                break;
            case 'file':
                $template = '@ChamiloTheme/Resource/new.html.twig';
                break;
        }

        return $this->render(
            $template,
            [
                'form' => $form->createView(),
                'parent' => $resourceNodeParentId,
                'file_type' => $fileType,
            ]
        );
    }
}
