<?php

namespace App\Controller;

use App\Repository\CounterRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CounterController extends AbstractController
{
    #[Route('/', name: 'app_counter', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        CounterRepository $counterRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $counter = $counterRepository->getOrCreateCounter();

        if ($request->isMethod('POST')) {
            $counter->increment();
            $entityManager->flush();

            return $this->redirectToRoute('app_counter');
        }

        return $this->render('counter/index.html.twig', [
            'counter' => $counter,
        ]);
    }
}
