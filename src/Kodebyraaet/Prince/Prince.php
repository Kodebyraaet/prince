<?php

namespace Kodebyraaet\Prince;

use File;
use Illuminate\View\View;
use Illuminate\Http\Response;
use Response as ResponseFacade;
use Illuminate\Foundation\Application;
use Kodebyraaet\Prince\Exceptions\PrinceError;
use Kodebyraaet\Prince\Exceptions\UnableToExecute;

class Prince implements PrinceInterface
{

    /**
     * App Instance.
     *
     * @var Application
     */
    private $app;

    /**
     * Path to the Prince executable.
     *
     * @var string
     */
    private $executable;

    /**
     * Commandline arguments to send to the executable.
     *
     * @var string
     */
    public $arguments;

    /**
     * Echo out error messages.
     *
     * @var bool
     */
    private $displayErrors;

    /**
     * Enable UTF8
     *
     * @var bool
     */
    private $useUtf8;

    /**
     * Holds the markup that should be sent to the prince executable.
     *
     * @var string
     */
    private $markup = '';

    /**
     * Prince constructor.
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;

        $this->executable = getenv('PRINCE_EXECUTABLE_PATH') ? getenv('PRINCE_EXECUTABLE_PATH') : '/usr/bin/prince';

        $this->arguments = ['--server', '-i "html"', '--silent', '-'];

        $this->displayErrors = true;

        $this->useUtf8 = false;
    }

    /**
     * Sets command line arguments
     *
     * @param Array $arguments
     * @return $this
     */
    public function setArguments($arguments)
    {
        $this->arguments = array_merge($this->arguments, $arguments);

        return $this;
    }

    /**
     * Takes a View and returns $this to further chain methods on.
     *
     * @param View $view
     * @return $this
     */
    public function view(View $view)
    {
        $this->markup .= $view->render();

        return $this;
    }

    /**
     * Takes a HTML string and returns $this to further chain methods on.
     *
     * @param $html
     * @return $this
     */
    public function html($html)
    {
        $this->markup .= $html;

        return $this;
    }

    /**
     * Returns the generated PDF as as a Response.
     *
     * @param string|null $filename
     * @param bool $attachment
     * @return Response
     * @throws UnableToExecute
     */
    public function download($filename = null, $attachment = false)
    {
        $data = $this->generate();

        $this->reset();

        $headers = [
            'Content-Type' => 'application/pdf',
        ];

        if ($filename != null) {
            if ($attachment) {
                $headers['Content-Disposition'] = 'attachment; filename="' . $filename . '"';
            } else {
                $headers['Content-Disposition'] = 'inline; filename="' . $filename . '"';
            }
        }

        return ResponseFacade::stream(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputs($out, $data);
            fclose($out);
        }, 200, $headers);
    }

    /**
     * Stores the generated PDF in at a provided path.
     *
     * @param $path
     * @return mixed
     */
    public function store($path)
    {
        $data = $this->generate();

        File::put($path, $data);

        $this->reset();

        return $path;
    }

    /**
     * Does the actual generation of a PDF. Returns raw PDF data for further
     * processing.
     *
     * @return string
     * @throws UnableToExecute
     */
    private function generate()
    {
        $markup = ($this->useUtf8) ? utf8_decode($this->markup) : $this->markup;

        // The different ways we want to communicate with the executable.
        $descriptorSpec = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "w")
        );

        // Tries to open up a process of prince at $this->executable path,
        // appends $this->arguments to the executable call.
        if (!is_resource($process = proc_open($this->executable . ' ' . implode(' ', $this->arguments), $descriptorSpec, $pipes))) {
            throw new UnableToExecute('Unable to open a prince executable at path ' . $this->executable . '.');
        }

        // Write all the markup to the pipe we can write to
        // and tell the executable we're done sending markup.
        fwrite($pipes[0], $markup);
        fclose($pipes[0]);

        // Get the pure response from the executable.
        $rawData = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        // Check if the executable gave us any errors.
        $this->parseErrors($pipes[2]);
        fclose($pipes[2]);

        // End the executable in it's entirety.
        proc_close($process);

        $this->reset();

        return $rawData;
    }

    /**
     * Checks if there are any errors in the pipeline.
     *
     * @param resource $pipe
     * @throws PrinceError
     * @return bool
     */
    protected function parseErrors($pipe)
    {
        // While the executable is still piping data.
        while (!feof($pipe)) {
            $line = fgets($pipe);

            if ($line) {
                $tag = substr($line, 0, 3);
                $body = substr($line, 4);

                if ($tag == 'fin') {
                    // End of data.
                    return false;
                }

                $messages[] = $body;
            }
        }

        // If we have any errors we throw a PrinceError.
        if (isset($messages)) {
            $errorMessage = 'Prince error: ' . implode(', ', $messages);

            throw new PrinceError($errorMessage);
        }

        return true;
    }

    /**
     * Prepares the object for re-use.
     */
    protected function reset()
    {
        $this->markup = '';
    }
}
