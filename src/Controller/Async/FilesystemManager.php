<?php
namespace Bolt\Controller\Async;

use Bolt\Translation\Translator as Trans;
use League\Flysystem\FileNotFoundException;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Async controller for filesystem management async routes.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 * @author Carson Full <carsonfull@gmail.com>
 */
class FilesystemManager extends AsyncBase
{
    protected function addRoutes(ControllerCollection $ctr)
    {
        $ctr->get('/browse/{namespace}/{path}', 'browse')
            ->assert('path', '.*')
            ->value('namespace', 'files')
            ->value('path', '')
            ->bind('asyncbrowse');

        $ctr->get('/file/autocomplete', 'filesAutoComplete')
            ->bind('file/autocomplete');

        $ctr->post('/file/create', 'createFile')
            ->bind('file/create');

        $ctr->post('/file/delete', 'deleteFile')
            ->bind('file/delete');

        $ctr->post('/file/duplicate', 'duplicateFile')
            ->bind('file/duplicate');

        $ctr->post('/file/rename', 'renameFile')
            ->bind('file/rename');

        $ctr->post('/folder/create', 'createFolder')
            ->bind('createfolder');

        $ctr->post('/folder/rename', 'renameFolder')
            ->bind('renamefolder');

        $ctr->post('/folder/remove', 'removeFolder')
            ->bind('removefolder');

        $ctr->get('/recordbrowser', 'recordBrowser')
            ->bind('recordbrowser');
    }

    /**
     * List browse on the server, so we can insert them in the file input.
     *
     * @param Request $request
     * @param string  $namespace
     * @param string  $path
     *
     * @return mixed
     */
    public function browse(Request $request, $namespace, $path)
    {
        // No trailing slashes in the path.
        $path = rtrim($path, '/');

        $filesystem = $this->filesystem()->getFilesystem($namespace);

        // $key is linked to the fieldname of the original field, so we can
        // Set the selected value in the proper field
        $key = $request->query->get('key');

        // Get the pathsegments, so we can show the path.
        $pathsegments = [];
        $cumulative = '';
        if (!empty($path)) {
            foreach (explode('/', $path) as $segment) {
                $cumulative .= $segment . '/';
                $pathsegments[$cumulative] = $segment;
            }
        }

        try {
            $filesystem->listContents($path);
        } catch (\Exception $e) {
            $msg = Trans::__("Folder '%s' could not be found, or is not readable.", ['%s' => $path]);
            $this->flashes()->error($msg);
        }

        list($files, $folders) = $filesystem->browse($path, $this->app);

        $context = [
            'namespace'    => $namespace,
            'files'        => $files,
            'folders'      => $folders,
            'pathsegments' => $pathsegments,
            'key'          => $key
        ];

        return $this->render('files_async/files_async.twig',
            ['context' => $context],
            ['title', Trans::__('Files in %s', ['%s' => $path])]
        );
    }

    /**
     * Create a new folder.
     *
     * @param Request $request
     *
     * @return Boolean Whether the creation was successful
     */
    public function createFolder(Request $request)
    {
        $namespace = $request->request->get('namespace');
        $parentPath = $request->request->get('parent');
        $folderName = $request->request->get('foldername');

        try {
            return $this->filesystem()->createDir("$namespace://$parentPath$folderName");
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create an empty file.
     *
     * @param Request $request
     *
     * @return bool
     */
    public function createFile(Request $request) {
        $namespace = $request->request->get('namespace');
        $parentPath = $request->request->get('parentPath');
        $filename = $request->request->get('filename');

        try {
            return $this->filesystem()->put("$namespace://$parentPath/$filename", ' ');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Delete a file on the server.
     *
     * @param Request $request
     *
     * @return bool
     */
    public function deleteFile(Request $request)
    {
        $namespace = $request->request->get('namespace');
        $filename = $request->request->get('filename');

        try {
            return $this->filesystem()->delete("$namespace://$filename");
        } catch (FileNotFoundException $e) {
            return false;
        }
    }

    /**
     * Duplicate a file on the server.
     *
     * @param Request $request
     *
     * @return bool
     */
    public function duplicateFile(Request $request)
    {
        $namespace = $request->request->get('namespace');
        $filename = $request->request->get('filename');

        $filesystem = $this->filesystem()->getFilesystem($namespace);

        $extensionPos = strrpos($filename, '.');
        $destination = substr($filename, 0, $extensionPos) . '_copy' . substr($filename, $extensionPos);
        $n = 1;

        while ($filesystem->has($destination)) {
            $extensionPos = strrpos($destination, '.');
            $destination = substr($destination, 0, $extensionPos) . "$n" . substr($destination, $extensionPos);
            $n = rand(0, 1000);
        }
        if ($filesystem->copy($filename, $destination)) {
            return true;
        }

        return false;
    }

    /**
     * Return autocomplete data for a file name.
     *
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function filesAutoComplete(Request $request)
    {
        $term = $request->get('term');
        $extensions = $request->query->get('ext');

        $files = $this->filesystem()->search($term, $extensions);

        return $this->json($files);
    }

    /**
     * List records to easily insert links through the WYSIWYG editor.
     *
     * @return mixed
     */
    public function recordBrowser()
    {
        $results = [];

        foreach ($this->app['storage']->getContentTypes() as $contenttype) {
            $records = $this->getContent($contenttype, ['published' => true, 'hydrate' => false]);

            foreach ($records as $record) {
                $results[$contenttype][] = [
                    'title' => $record->getTitle(),
                    'id'    => $record->id,
                    'link'  => $record->link()
                ];
            }
        }

        $context = [
            'results' => $results,
        ];

        return $this->render('recordbrowser/recordbrowser.twig', ['context' => $context]);
    }

    /**
     * Delete a folder recursively if writeable.
     *
     * @param Request $request
     *
     * @return Boolean Whether the renaming action was successful
     */
    public function removeFolder(Request $request)
    {
        $namespace = $request->request->get('namespace');
        $parentPath = $request->request->get('parent');
        $folderName = $request->request->get('foldername');

        try {
            return $this->filesystem()->deleteDir("$namespace://$parentPath$folderName");
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Rename a file within the files directory tree.
     *
     * @param Request $request
     *
     * @return Boolean Whether the renaming action was successful
     */
    public function renameFile(Request $request)
    {
        $namespace  = $request->request->get('namespace');
        $parentPath = $request->request->get('parent');
        $oldName    = $request->request->get('oldname');
        $newName    = $request->request->get('newname');

        try {
            return $this->filesystem()->rename("$namespace://$parentPath/$oldName", "$parentPath/$newName");
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Rename a folder within the files directory tree.
     *
     * @param Request $request
     *
     * @return Boolean Whether the renaming action was successful
     */
    public function renameFolder(Request $request)
    {
        $namespace  = $request->request->get('namespace');
        $parentPath = $request->request->get('parent');
        $oldName    = $request->request->get('oldname');
        $newName    = $request->request->get('newname');

        try {
            return $this->filesystem()->rename("$namespace://$parentPath$oldName", "$parentPath$newName");
        } catch (\Exception $e) {
            return false;
        }
    }
}
