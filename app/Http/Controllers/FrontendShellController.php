<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class FrontendShellController extends Controller
{
    public function showShell(Request $request): View
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return view('app.shell', [
            'initialPage' => $this->defaultPageForRole($user->role),
            'navbarMarkup' => $this->navbarMarkup(),
            'user' => $user,
        ]);
    }

    public function showStudentPage(string $page): Response
    {
        return $this->showPage('student', $page);
    }

    public function showProfessorPage(string $page): Response
    {
        return $this->showPage('professor', $page);
    }

    public function navbarCss(): Response
    {
        return $this->assetResponse(
            base_path('frontend/components/navbar/navbar.css'),
            'text/css; charset=UTF-8'
        );
    }

    public function navbarJs(): Response
    {
        return $this->assetResponse(
            base_path('frontend/components/navbar/navbar.js'),
            'application/javascript; charset=UTF-8'
        );
    }

    public function frontendAsset(string $path): Response
    {
        $relativePath = ltrim(str_replace('\\', '/', $path), '/');

        abort_if($relativePath === '' || str_contains($relativePath, '..'), 404);

        $assetsRoot = realpath(base_path('frontend/assets'));

        abort_if($assetsRoot === false, 404);

        $assetPath = realpath($assetsRoot.DIRECTORY_SEPARATOR.$relativePath);

        abort_if($assetPath === false, 404);
        abort_unless(str_starts_with($assetPath, $assetsRoot.DIRECTORY_SEPARATOR), 404);
        abort_unless(is_file($assetPath), 404);

        return $this->assetResponse($assetPath, $this->contentTypeForAsset($assetPath));
    }

    private function showPage(string $role, string $page): Response
    {
        $safePage = basename($page);
        $path = base_path("frontend/pages/{$role}/{$safePage}.html");

        abort_unless(is_file($path), 404);

        return $this->assetResponse($path, 'text/html; charset=UTF-8');
    }

    private function assetResponse(string $path, string $contentType): Response
    {
        abort_unless(is_file($path), 404);

        $content = file_get_contents($path);

        abort_if($content === false, 404);

        return response($content, 200, ['Content-Type' => $contentType]);
    }

    private function contentTypeForAsset(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=UTF-8',
            'js' => 'application/javascript; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            default => 'application/octet-stream',
        };
    }

    private function defaultPageForRole(string $role): string
    {
        return $role === 'professor'
            ? '/pages/professor/professor-home.html'
            : '/pages/student/student-home.html';
    }

    private function navbarMarkup(): string
    {
        $html = file_get_contents(base_path('frontend/components/navbar/navbar.html'));

        abort_if($html === false, 500);

        preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $matches);

        $body = $matches[1] ?? $html;

        return (string) preg_replace('/<script\b[^>]*src="navbar\.js"[^>]*><\/script>/i', '', $body);
    }
}
