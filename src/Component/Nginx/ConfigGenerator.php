<?php


namespace App\Component\Nginx;

use App\Component\ServiceManager;
use App\Entity\Endpoint;
use App\Repository\StreamsRepository;

/**
 * Class ConfigGenerator
 * @author Soner Sayakci <shyim@posteo.de>
 */
class ConfigGenerator
{
    private const VHOST = "\t\tapplication %s {
\t\t\tlive on;
\t\t\tmeta copy;
%s
\t\t}";

    private const NGINX_CONF = "user nginx;
worker_processes 2;
pid /run/nginx-rtmp.pid;
error_log logs/error.log;

events {
\tworker_connections 1024;
}

rtmp {
\tserver {
\t\tlisten 1935;
\t}
}

http {
\taccess_log logs/access.log;
\tinclude mime.types;

\tserver {
\t\tlisten 26765;
\t\tlocation /stat {
\t\t\trtmp_stat all;
\t\t}
\t}

\tserver {
\t\tlisten 80;

\t\troot /opt/panel/public;

\t\tlocation / {
\t\t\ttry_files chr(36)uri /index.phpchr(36)is_argschr(36)args;
\t\t}

\t\tlocation ~ ^/index\.php(/|chr(36)) {
\t\t\tfastcgi_pass 127.0.0.1:9000;
\t\t\t\tfastcgi_split_path_info ^(.+\.php)(/.*)chr(36);
\t\t\t\tinclude fastcgi_params;
\t\t\t\tfastcgi_param SCRIPT_FILENAME chr(36)realpath_rootchr(36)fastcgi_script_name;
\t\t\t\tfastcgi_param DOCUMENT_ROOT chr(36)realpath_root;
\t\t\t\tinternal;
\t\t\t}
\t\tlocation ~ \.phpchr(36) {
\t\t\treturn 404;
\t\t}\t\t
\t}
}
";

    /**
     * @var StreamsRepository
     */
    private $repository;

    /**
     * @var ServiceManager
     */
    private $manager;
    /**
     * @var string
     */
    private $nginxConfigFolder;

    /**
     * @var string
     */
    private $appHost;

    /**
     * ConfigGenerator constructor.
     * @param StreamsRepository $repository
     * @param ServiceManager $manager
     * @param string $nginxConfigFolder
     * @param string $appHost
     * @author Soner Sayakci <shyim@posteo.de>
     */
    public function __construct(StreamsRepository $repository, ServiceManager $manager, string $nginxConfigFolder, string $appHost)
    {
        $this->repository = $repository;
        $this->manager = $manager;
        $this->nginxConfigFolder = $nginxConfigFolder;
        $this->appHost = $appHost;
    }

    /**
     * @author Soner Sayakci <shyim@posteo.de>
     * @throws \Exception
     */
    public function generate(): void
    {
        if (!file_exists($this->nginxConfigFolder)) {
            if (!mkdir($this->nginxConfigFolder, 7777, true) && !is_dir($this->nginxConfigFolder)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $this->nginxConfigFolder));
            }
        }

        $vhost = '';

        foreach ($this->getStreams() as $stream) {
            if ($stream->getEndpoints()->count()) {
                $configPush = [];

                $configPush[] = "\n\t\t\t# Events";
                $configPush[] = "\t\t\ton_publish " . $this->appHost . '/events/onPublish;';
                $configPush[] = "\t\t\ton_done " . $this->appHost . '/events/onDone;';
                $configPush[] = "\n\t\t\t# Pushes";

                foreach ($stream->getEndpoints() as $endpoint) {
                    $configPush[] = "\t\t\tpush " . $this->buildUrl($endpoint) . ';';
                }

                $applicationName = sprintf('%s/%s', $stream->getUser()->getUsername(), $stream->getName());
                $vhost .= sprintf(self::VHOST, $applicationName, implode("\n", $configPush));
            }
        }

        file_put_contents($this->nginxConfigFolder . '/nginx.conf', sprintf(self::NGINX_CONF, $vhost));
    }

    /**
     * @return \App\Entity\Streams[]
     * @author Soner Sayakci <shyim@posteo.de>
     */
    private function getStreams(): array
    {
        return $this->repository->getActiveStreams();
    }

    /**
     * @param Endpoint $endpoint
     * @return string
     * @author Soner Sayakci <shyim@posteo.de>
     */
    private function buildUrl(Endpoint $endpoint): string
    {
        $service = $this->manager->getServiceByName($endpoint->getType());
        return $service->buildStreamUrl($endpoint);
    }
}
