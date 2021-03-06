<?php

namespace ArusDomainBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\StreamedResponse;

use ArusDomainBundle\Entity\ArusDomain;
use ArusDomainBundle\Form\ArusDomainEditType;
use ArusDomainBundle\Form\ArusDomainQuickEditType;

use ArusDomainBundle\Entity\Multiple;
use ArusDomainBundle\Form\AddMultipleType;

use ArusDomainBundle\Entity\Import;
use ArusDomainBundle\Form\ImportType;

use ArusDomainBundle\Entity\Search;
use ArusDomainBundle\Form\SearchType;
use ArusDomainBundle\Form\ExportType;

use ArusEntityTaskBundle\Entity\ArusEntityTask;
use ArusEntityTaskBundle\Entity\Search as EntityTaskSearch;


class DefaultController extends Controller
{
	public function indexAction(Request $request)
    {
        $t_status = $this->getParameter('domain')['status'];

		$search = new Search();
		$search_form = $this->createForm( new SearchType(['t_status'=>$t_status]), $search );
		$search_form->handleRequest($request);

		$export_form = $this->createForm( new ExportType(['t_status'=>$t_status]), $search, ['action'=>$this->generateUrl('domain_export')] );

		$data = null;
		if( $search_form->isSubmitted() && $search_form->isValid() )  {
			$data = $search_form->getData();
		}

		$page = 1;
		$limit = $this->getParameter('results_per_page');
		$total_domain = $this->get('domain')->search( $data, -1 );
		$n_page = ceil( $total_domain/$limit );

		if( is_array($data) || is_object($data) ) {
			if (is_array($data) && isset($data['page'])) {
				$page = $data['page'];
			} else {
				$page = $data->getPage();
			}
			if ($page <= 0 || $page > $n_page) {
				$page = 1;
			}
		}

		$t_domain = $this->get('domain')->search( $data, $page );
		foreach( $t_domain as $d ) {
			$d->setEntityAlerts( $this->get('entity_alert')->search(['entity_id'=>$d->getEntityId()]) );
		}

		$t_actions = [ 'export csv'=>'javascript:;' ];
		$pagination = $this->get('app')->paginate( $total_domain, count($t_domain), $page, $t_actions );

		return $this->render('ArusDomainBundle:Default:index.html.twig', array(
			'search_form' => $search_form->createView(),
			'export_form' => $export_form->createView(),
			't_domain' => $t_domain,
            't_status' => $t_status,
			'pagination' => $pagination,
		));
    }


	/**
	 * Finds and displays a ArusDomain entity.
	 *
	 */
	public function showAction(Request $request, ArusDomain $domain)
	{
        $t_status = $this->getParameter('domain')['status'];
		$quick_edit = $this->createForm(new ArusDomainQuickEditType(['t_status'=>$t_status]), $domain, ['action'=>$this->generateUrl('domain_quickedit',['id'=>$domain->getId()])] );

        $deleteForm = $this->createDeleteForm($domain);

		$alert_mod = $this->get('entity_alert')->getModAction( $domain );
		$task_mod = $this->get('entity_task')->getModAction( $domain );

        $t_host = $this->get('host')->search( ['domain'=>$domain] );
        foreach( $t_host as $h ) {
            $h->setEntityAlerts( $this->get('entity_alert')->search(['entity_id'=>$h->getEntityId()]) );
        }

        return $this->render('ArusDomainBundle:Default:show.html.twig', array(
			'domain' => $domain,
			'delete_form' => $deleteForm->createView(),
			'alert_mod' => $alert_mod,
			'task_mod' => $task_mod,
            't_host' => $t_host,
            't_status' => $t_status,
            'quick_edit' => $quick_edit->createView(),
		));
	}

	
	/**
	 * Create a new ArusDomain entity.
	 *
	 */
	public function addAction(Request $request, $project_id )
	{
		$multiple = new Multiple();
		$import = new Import();

		if( $project_id ) {
			$project = $this->getDoctrine()->getRepository('ArusProjectBundle:ArusProject')->find( $project_id );
			if( $project ) {
				$multiple->setProject( $project );
				$import->setProject( $project );
			}
		}

		$multiple_form = $this->createForm( new AddMultipleType(), $multiple, ['action'=>$this->generateUrl('domain_add_multiple')] );
		$import_form = $this->createForm( new ImportType(), $import, ['action'=>$this->generateUrl('domain_add_import')] );

		return $this->render('ArusDomainBundle:Default:add.html.twig', array(
			'multiple_form' => $multiple_form->createView(),
			'import_form' => $import_form->createView(),
		));
	}
	
	
	/**
	 * Create a new ArusDomain entity.
	 *
	 */
	public function addMultipleAction(Request $request)
	{
		$multiple = new Multiple();
		$form = $this->createForm( new AddMultipleType(), $multiple );
		$form->handleRequest( $request );

		if ($form->isSubmitted() && $form->isValid()) {
			$project = $multiple->getProject();
			$t_domain = explode( "\n", $multiple->getNames() );
			$cnt = $this->get('domain')->import( $project, $t_domain, $multiple->getRecon() );
			$this->addFlash( 'success', $cnt.' domain added!' );
			return $this->redirectToRoute( 'project_show',array('id'=>$project->getId()) );
		}

		$this->addFlash( 'danger', 'Error!' );
		return $this->redirectToRoute( 'domain_homepage' );
	}

	
	/**
	 * Import Domain from file
	 *
	 */
	public function addImportAction(Request $request )
	{
		$import = new Import();
		$form = $this->createForm( new ImportType(), $import );
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {
			$project = $import->getProject();
			$source_file = $import->getSourceFile();
			$t_domain = file( $source_file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES );
			$cnt = $this->get('domain')->import( $project, $t_domain, $import->getRecon() );
			$this->addFlash( 'success', $cnt.' domain imported!' );
			return $this->redirectToRoute( 'project_show',array('id'=>$project->getId()) );
		}

		$this->addFlash( 'danger', 'Error!' );
		return $this->redirectToRoute( 'domain_homepage' );
	}

	
	/**
	 * Finds and displays a ArusDomain entity.
	 *
	 */
	public function viewAction(Request $request, ArusDomain $domain)
	{
        $t_status = $this->getParameter('domain')['status'];
		$quick_edit = $this->createForm(new ArusProjectQuickEditType(['t_status'=>$t_status]), $domain, ['action'=>$this->generateUrl('domain_quickedit',['id'=>$domain->getId()])] );

		return $this->render('ArusDomainBundle:Default:view.html.twig', array(
			'domain' => $domain,
            't_status' => $t_status,
            'quick_edit' => $quick_edit->createView(),
		));
	}


	/**
	 * Displays a form to edit an existing Domain entity.
	 *
	 */
	public function quickeditAction(Request $request, ArusDomain $domain)
	{
		$r = ['error'=>0];
        $t_status = $this->getParameter('domain')['status'];

		$form = $this->createForm( new ArusDomainQuickEditType(['t_status'=>$t_status]), $domain, ['action'=>$this->generateUrl('domain_quickedit',['id'=>$domain->getId()])] );
		$form->handleRequest($request);

		if( $form->isSubmitted() && $form->isValid() ) {
			$em = $this->getDoctrine()->getManager();
			$em->persist( $domain );
			$em->flush();
		}

		$response = new Response( json_encode($r) );
		return $response;
	}


	/**
	 * Displays a form to edit an existing ArusDomain entity.
	 *
	 */
	public function editAction(Request $request, ArusDomain $domain)
	{
        $t_status = $this->getParameter('domain')['status'];

		$form = $this->createForm(new ArusDomainEditType(['t_status'=>$t_status]), $domain, ['action'=>$this->generateUrl('domain_edit',['id'=>$domain->getId()])] );
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {
			$exist = $this->get('domain')->exist( $domain->getProject(), $domain->getName(), $domain->getId() );
			if( !$exist ) {
				$em = $this->getDoctrine()->getManager();
				$em->persist( $domain );
				$em->flush();
				$this->addFlash( 'success', 'Your changes were saved!' );
			} else {
				$this->addFlash( 'danger', 'Error!' );
			}
			return $this->redirectToRoute('domain_show',array('id'=>$domain->getId()));
		}

		return $this->render('ArusDomainBundle:Default:edit.html.twig', array(
			'domain' => $domain,
			'form' => $form->createView(),
		));
	}


	/**
	 * Deletes a ArusDomain entity.
	 *
	 */
	public function deleteAction(Request $request, ArusDomain $domain)
	{
		$form = $this->createDeleteForm($domain);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {
			$this->get('app')->entityDelete( $domain );
			$em = $this->getDoctrine()->getManager();
			$em->remove( $domain );
			$em->flush();

			$this->addFlash( 'success', 'Domain deleted!' );
		}

		return $this->redirectToRoute('domain_homepage');
	}


	/**
	 * Creates a form to delete a ArusDomain entity.
	 *
	 * @param ArusDomain $domain The ArusDomain entity
	 *
	 * @return \Symfony\Component\Form\Form The form
	 */
	private function createDeleteForm(ArusDomain $domain)
	{
		return $this->createFormBuilder()
		->setAction($this->generateUrl('domain_delete', array('id' => $domain->getId())))
		->setMethod('DELETE')
		->getForm()
			;
	}
	
	
	/**
	 * switch the survey flag
	 *
	 * @param Request $request
	 * @param ArusDomain $domain
	 * @return unknown
	 */
	public function surveyAction(Request $request, ArusDomain $domain)
	{
		$r = ['error'=>0];
		$this->get('domain')->survey( $domain );
		$r['survey'] = $domain->getSurvey();
		$response = new Response( json_encode($r) );
		return $response;
	}
	
	
	/**
	 * Export search result
	 *
	 */
	public function exportAction( Request $request )
	{
		$t_status = $this->getParameter('domain')['status'];

		$search = new Search();
		$export_form = $this->createForm( new ExportType(['t_status'=>$t_status]), $search, ['action'=>$this->generateUrl('domain_export')] );
		$export_form->handleRequest( $request );

		$data = null;
		if( $export_form->isSubmitted() && $export_form->isValid() )  {
			$data = $export_form->getData();
		}
		//var_dump( $data );

		if( $data->getExportFull() == 'page' ) {
			$page = 1;
			$limit = $this->getParameter('results_per_page');
			$total_domain = $this->get('domain')->search( $data, -1 );
			$n_page = ceil( $total_domain/$limit );
	
			if( is_array($data) || is_object($data) ) {
				if (is_array($data) && isset($data['page'])) {
					$page = $data['page'];
				} else {
					$page = $data->getPage();
				}
				if ($page <= 0 || $page > $n_page) {
					$page = 1;
				}
			}
			$limit = -1;
		} else {
			$page = 1;
			$limit = null;
		}
		
		$t_domain = $this->get('domain')->search( $data, $page, $limit );
		
		$response = new StreamedResponse();
		$response->setCallback(function() use($data,$t_domain) {
			$t_field = [];
			if( $data->getExportId() ) {
				$t_field[] = 'id';
			}
			if( $data->getExportProject() ) {
				$t_field[] = 'project';
			}
			if( $data->getExportName() ) {
				$t_field[] = 'name';
			}
			if( $data->getExportCreatedAt() ) {
				$t_field[] = 'created_date';
			}
			$handle = fopen( 'php://output', 'w+' );
			fputcsv( $handle, $t_field, ';' );
			foreach( $t_domain as $o ) {
				$tmp = [];
				if( $data->getExportId() ) {
					$tmp[] = $o->getId();
				}
				if( $data->getExportProject() ) {
					$tmp[] = $o->getProject()->getName();
				}
				if( $data->getExportName() ) {
					$tmp[] = $o->getName();
				}
				if( $data->getExportCreatedAt() ) {
					$tmp[] = date( 'Y/m/d', $o->getCreatedAt()->getTimestamp() );
				}
				fputcsv( $handle, $tmp,';' );
			}
			fclose( $handle );
		});
		
		$response->setStatusCode( 200 );
		$response->headers->set( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->headers->set( 'Content-Disposition','attachment; filename="domain.csv"' );
		
		return $response;            
	}
}
