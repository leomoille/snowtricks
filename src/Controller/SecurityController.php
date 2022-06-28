<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ForgotPasswordType;
use App\Form\RegistrationType;
use App\Form\ResetPasswordType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\TokenGenerator\TokenGeneratorInterface;

class SecurityController extends AbstractController
{
    /**
     * @Route("/inscription", name="security_registration")
     */
    public function registration(
        Request $request,
        EntityManagerInterface $manager,
        UserPasswordHasherInterface $encoder
    ): Response {
        $user = new User();

        $form = $this->createForm(RegistrationType::class, $user);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $hash = $encoder->hashPassword($user, $user->getPassword());
            $user->setPassword($hash);
            $manager->persist($user);
            $manager->flush();

            return $this->redirectToRoute('security_login');
        }

        return $this->render('security/registration.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/connexion", name="security_login")
     */
    public function login(): Response
    {
        return $this->render('security/login.html.twig');
    }

    /**
     * @Route("/deconnexion", name="security_logout")
     */
    public function logout()
    {
    }

    /**
     * @Route("/mot-de-passe-oublie", name="security_forgot_password")
     */
    public function forgotPassword(
        Request $request,
        MailerInterface $mailer,
        UserRepository $userRepository,
        TokenGeneratorInterface $tokenGenerator,
        EntityManagerInterface $entityManager
    ): Response {
        $form = $this->createForm(ForgotPasswordType::class);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $userRepository->findOneByEmail($form->get('email')->getData());

            if ($user) {
                $token = $tokenGenerator->generateToken();
                $user->setResetToken($token);
                $entityManager->persist($user);
                $entityManager->flush();

                $url = $this->generateUrl(
                    'security_reset_password',
                    ['token' => $token],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );

                $email = (new TemplatedEmail())
                    ->from('no-reply@snowtricks.dev')
                    ->to($user->getEmail())
                    ->subject('Réinitialisation de mot de passe')
                    ->htmlTemplate('email/password-recovery.html.twig')
                    ->context([
                        'url' => $url,
                        'user' => $user,
                    ]);

                $mailer->send($email);

                $this->addFlash(
                    'notice',
                    'Top, tu vas bientôt pouvoir retrouver ton compte !'
                );
            } else {
                $this->addFlash(
                    'warning',
                    'Désolé mais personne n\'a été trouvé avec ce mail...'
                );

                return $this->redirectToRoute('security_forgot_password');
            }
        }

        return $this->render('security/forgot-password.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/mot-de-passe-oublie/{token}", name="security_reset_password")
     */
    public function resetPassword(
        string $token,
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $user = $userRepository->findOneByResetToken($token);

        if ($user) {
            $form = $this->createForm(ResetPasswordType::class);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                dump($form->get('password')->getData());
                $user->setResetToken('');
                $user->setPassword(
                    $passwordHasher->hashPassword(
                        $user,
                        $form->get('password')->getData()
                    )
                );

                $entityManager->persist($user);
                $entityManager->flush();

                $this->addFlash('success', 'Ton mot de passe à bien été changé !');

                return $this->redirectToRoute('security_login');
            }

            return $this->render('security/reset-password.html.twig', [
                'form' => $form->createView(),
            ]);
        }

        $this->addFlash('warning', 'Jeton invalide');

        return $this->redirectToRoute('security_forgot_password');
    }
}
