<?php

namespace App\Controller;

use App\Entity\TruckConfiguration;
use App\Entity\DeviceRole;
use App\Entity\Device;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/configuration')]
#[IsGranted('ROLE_USER')]
class ConfigurationController extends AbstractController
{
    #[Route('/', name: 'configuration_index')]
    public function index(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        // Get all configurations for this user
        $configurations = $em->getRepository(TruckConfiguration::class)
            ->createQueryBuilder('tc')
            ->where('tc.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('tc.isActive', 'DESC')
            ->addOrderBy('tc.lastUsed', 'DESC')
            ->getQuery()
            ->getResult();

        // Get attention needed items
        $attentionNeeded = $this->getAttentionNeededItems($em, $user, $configurations);

        return $this->render('configuration/index.html.twig', [
            'configurations' => $configurations,
            'attentionNeeded' => $attentionNeeded,
        ]);
    }

    #[Route('/new', name: 'configuration_new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');

            if (!$name) {
                $this->addFlash('error', 'Configuration name is required.');
                return $this->redirectToRoute('configuration_new');
            }

            $configuration = new TruckConfiguration();
            $configuration->setName($name);
            $configuration->setOwner($user);
            $configuration->setIsActive(false);

            $em->persist($configuration);
            $em->flush();

            $this->addFlash('success', 'Configuration created successfully!');
            return $this->redirectToRoute('configuration_edit', ['id' => $configuration->getId()]);
        }

        // Get available devices for this user
        $devices = $this->getUserDevices($em, $user);

        return $this->render('configuration/new.html.twig', [
            'devices' => $devices,
        ]);
    }

    #[Route('/{id}', name: 'configuration_show', requirements: ['id' => '\d+'])]
    public function show(int $id, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $configuration = $em->getRepository(TruckConfiguration::class)->find($id);

        if (!$configuration || $configuration->getOwner() !== $user) {
            throw $this->createNotFoundException('Configuration not found');
        }

        // Get axle groups with their status
        $axleGroups = [];
        foreach ($configuration->getAxleGroups() as $axleGroup) {
            $axleGroups[] = [
                'entity' => $axleGroup,
                'status' => $axleGroup->getCalibrationStatus(),
                'points' => $axleGroup->getMinCalibrationPoints(),
            ];
        }

        return $this->render('configuration/show.html.twig', [
            'configuration' => $configuration,
            'axleGroups' => $axleGroups,
        ]);
    }

    #[Route('/{id}/edit', name: 'configuration_edit')]
    public function edit(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $configuration = $em->getRepository(TruckConfiguration::class)->find($id);

        if (!$configuration || $configuration->getOwner() !== $user) {
            throw $this->createNotFoundException('Configuration not found');
        }

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            if ($name) {
                $configuration->setName($name);
                $em->flush();
                $this->addFlash('success', 'Configuration updated successfully!');
            }
        }

        $devices = $this->getUserDevices($em, $user);

        return $this->render('configuration/edit.html.twig', [
            'configuration' => $configuration,
            'devices' => $devices,
        ]);
    }

    #[Route('/{id}/activate', name: 'configuration_activate', methods: ['POST'])]
    public function activate(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $configuration = $em->getRepository(TruckConfiguration::class)->find($id);

        if (!$configuration || $configuration->getOwner() !== $user) {
            return new JsonResponse(['error' => 'Configuration not found'], 404);
        }

        // Deactivate all other configurations for this user
        $em->getRepository(TruckConfiguration::class)
            ->createQueryBuilder('tc')
            ->update()
            ->set('tc.isActive', ':false')
            ->where('tc.owner = :user')
            ->setParameter('false', false)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();

        // Activate this configuration
        $configuration->setIsActive(true);
        $configuration->setLastUsed(new \DateTime());
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Configuration activated',
        ]);
    }

    #[Route('/{id}/delete', name: 'configuration_delete', methods: ['POST'])]
    public function delete(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $configuration = $em->getRepository(TruckConfiguration::class)->find($id);

        if (!$configuration || $configuration->getOwner() !== $user) {
            return new JsonResponse(['error' => 'Configuration not found'], 404);
        }

        $em->remove($configuration);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Configuration deleted',
        ]);
    }

    #[Route('/{id}/add-device', name: 'configuration_add_device', methods: ['POST'])]
    public function addDevice(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $configuration = $em->getRepository(TruckConfiguration::class)->find($id);

        if (!$configuration || $configuration->getOwner() !== $user) {
            return new JsonResponse(['error' => 'Configuration not found'], 404);
        }

        $deviceId = $request->request->get('device_id');
        $role = $request->request->get('role', 'device');

        $device = $em->getRepository(Device::class)->find($deviceId);
        if (!$device) {
            return new JsonResponse(['error' => 'Device not found'], 404);
        }

        // Check if device already in this configuration
        foreach ($configuration->getDeviceRoles() as $existingRole) {
            if ($existingRole->getDevice() === $device) {
                return new JsonResponse(['error' => 'Device already in configuration'], 400);
            }
        }

        $deviceRole = new DeviceRole();
        $deviceRole->setDevice($device);
        $deviceRole->setTruckConfiguration($configuration);
        $deviceRole->setRole($role);
        $deviceRole->setSortOrder($configuration->getDeviceRoles()->count());

        $em->persist($deviceRole);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Device added to configuration',
        ]);
    }

    #[Route('/{configId}/remove-device/{deviceRoleId}', name: 'configuration_remove_device', methods: ['POST'])]
    public function removeDevice(int $configId, int $deviceRoleId, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $configuration = $em->getRepository(TruckConfiguration::class)->find($configId);

        if (!$configuration || $configuration->getOwner() !== $user) {
            return new JsonResponse(['error' => 'Configuration not found'], 404);
        }

        $deviceRole = $em->getRepository(DeviceRole::class)->find($deviceRoleId);
        if (!$deviceRole || $deviceRole->getTruckConfiguration() !== $configuration) {
            return new JsonResponse(['error' => 'Device role not found'], 404);
        }

        $em->remove($deviceRole);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Device removed from configuration',
        ]);
    }

    #[Route('/{id}/bulk-calibration', name: 'configuration_bulk_calibration')]
    public function bulkCalibration(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $configuration = $em->getRepository(TruckConfiguration::class)->find($id);

        if (!$configuration || $configuration->getOwner() !== $user) {
            throw $this->createNotFoundException('Configuration not found');
        }

        $axleGroups = [];
        foreach ($configuration->getAxleGroups() as $axleGroup) {
            $axleGroups[] = [
                'entity' => $axleGroup,
                'channels' => $axleGroup->getDeviceChannels()->toArray(),
            ];
        }

        if ($request->isMethod('POST')) {
            return $this->processBulkCalibration($configuration, $request, $em);
        }

        return $this->render('configuration/bulk_calibration.html.twig', [
            'configuration' => $configuration,
            'axleGroups' => $axleGroups,
        ]);
    }

    private function processBulkCalibration(TruckConfiguration $configuration, Request $request, EntityManagerInterface $em): Response
    {
        $ticketNumber = $request->request->get('ticket_number');
        $location = $request->request->get('location');
        $notes = $request->request->get('notes');
        $occurredAt = $request->request->get('occurred_at');
        $weights = $request->request->all('weights'); // Array of axleGroupId => weight

        // Create calibration session
        $session = new \App\Entity\CalibrationSession();
        $session->setTruckConfiguration($configuration);
        $session->setCreatedBy($this->getUser());
        $session->setSource('TRUCK_SCALE');
        $session->setTicketNumber($ticketNumber);
        $session->setLocation($location);
        $session->setNotes($notes);

        if ($occurredAt) {
            try {
                $session->setOccurredAt(new \DateTime($occurredAt));
            } catch (\Exception $e) {
                $session->setOccurredAt(new \DateTime());
            }
        }

        $em->persist($session);

        $calibrationsAdded = 0;
        $errors = [];

        foreach ($weights as $axleGroupId => $weight) {
            if (empty($weight) || $weight <= 0) {
                continue;
            }

            // Find the axle group
            $axleGroup = $em->getRepository(\App\Entity\AxleGroup::class)->find($axleGroupId);
            if (!$axleGroup) {
                continue;
            }

            // Get all channels in this axle group
            $channels = $axleGroup->getDeviceChannels();

            if ($channels->count() === 0) {
                $errors[] = "No channels found for " . $axleGroup->getLabel();
                continue;
            }

            // Get latest sensor data for each channel in this axle group
            foreach ($channels as $channel) {
                $device = $channel->getDevice();

                // Get latest micro data for this device
                $latestData = $em->getRepository(\App\Entity\MicroData::class)
                    ->createQueryBuilder('m')
                    ->where('m.device = :device')
                    ->setParameter('device', $device)
                    ->orderBy('m.id', 'DESC')
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();

                if (!$latestData) {
                    $errors[] = "No sensor data available for " . $channel->getDisplayLabel();
                    continue;
                }

                // Distribute weight across channels in this axle group
                $channelWeight = $weight / $channels->count();

                // Create calibration point
                $calibration = new \App\Entity\Calibration();
                $calibration->setDevice($device);
                $calibration->setDeviceChannel($channel);
                $calibration->setCreatedBy($this->getUser());
                $calibration->setCalibrationSession($session);
                $calibration->setScaleWeight($channelWeight);

                // Use channel-specific data if available
                $channelData = null;
                if ($latestData->getChannels() && count($latestData->getChannels()) > 0) {
                    foreach ($latestData->getChannels() as $chData) {
                        if ($chData['channel_index'] === $channel->getChannelIndex()) {
                            $channelData = $chData;
                            break;
                        }
                    }
                }

                if ($channelData) {
                    $calibration->setAirPressure($channelData['air_pressure'] ?? 0);
                } else {
                    // Fallback to main air pressure for channel 1
                    $calibration->setAirPressure($latestData->getMainAirPressure());
                }

                $calibration->setAmbientAirPressure($latestData->getAtmosphericPressure());
                $calibration->setAirTemperature($latestData->getTemperature());
                $calibration->setElevation($latestData->getElevation());
                $calibration->setComment("Truck scale: " . ($location ?: 'Unknown location'));

                $em->persist($calibration);
                $session->addCalibration($calibration);
                $calibrationsAdded++;
            }
        }

        if ($calibrationsAdded > 0) {
            $em->flush();

            // Run regression for affected devices
            $regressor = new \App\Service\DeviceCalibrationRegressor($em);
            foreach ($configuration->getDeviceRoles() as $deviceRole) {
                $device = $deviceRole->getDevice();
                if ($device) {
                    $regressor->run($device);
                }
            }

            $message = "Added {$calibrationsAdded} calibration point(s)";
            if (count($errors) > 0) {
                $message .= ", but encountered some errors.";
            }

            $this->addFlash('success', $message);

            if (count($errors) > 0) {
                foreach ($errors as $error) {
                    $this->addFlash('warning', $error);
                }
            }

            return $this->redirectToRoute('configuration_show', ['id' => $configuration->getId()]);
        } else {
            $this->addFlash('error', 'No calibration points were added. Please enter weights and ensure devices have recent sensor data.');
            if (count($errors) > 0) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }
            }
            return $this->redirectToRoute('configuration_bulk_calibration', ['id' => $configuration->getId()]);
        }
    }

    /**
     * Get all devices owned by or accessible to the user
     */
    private function getUserDevices(EntityManagerInterface $em, $user): array
    {
        // Devices user purchased
        $purchasedDevices = $em->getRepository(Device::class)->findBy(['soldTo' => $user]);
        $devices = [];
        foreach ($purchasedDevices as $device) {
            $devices[$device->getId()] = $device;
        }

        // Devices user has access to
        $accessRecords = $em->getRepository(\App\Entity\DeviceAccess::class)->findBy([
            'user' => $user,
            'isActive' => true
        ]);
        foreach ($accessRecords as $access) {
            $devices[$access->getDevice()->getId()] = $access->getDevice();
        }

        return array_values($devices);
    }

    /**
     * Get items that need attention
     */
    private function getAttentionNeededItems(EntityManagerInterface $em, $user, array $configurations): array
    {
        $items = [];

        foreach ($configurations as $config) {
            // Check each axle group for calibration issues
            foreach ($config->getAxleGroups() as $axleGroup) {
                $status = $axleGroup->getCalibrationStatus();
                $points = $axleGroup->getMinCalibrationPoints();

                if ($status === 'critical') {
                    $items[] = [
                        'type' => 'critical',
                        'message' => $axleGroup->getLabel() . ' needs calibration (0 points)',
                        'config' => $config,
                        'axleGroup' => $axleGroup,
                    ];
                } elseif ($status === 'warning') {
                    $items[] = [
                        'type' => 'warning',
                        'message' => $axleGroup->getLabel() . ' needs more calibration (' . $points . ' points)',
                        'config' => $config,
                        'axleGroup' => $axleGroup,
                    ];
                }
            }

            // Check for offline devices
            foreach ($config->getDeviceRoles() as $deviceRole) {
                $device = $deviceRole->getDevice();
                if ($device) {
                    $lastData = $em->getRepository(\App\Entity\MicroData::class)
                        ->createQueryBuilder('m')
                        ->where('m.device = :device')
                        ->setParameter('device', $device)
                        ->orderBy('m.id', 'DESC')
                        ->setMaxResults(1)
                        ->getQuery()
                        ->getOneOrNullResult();

                    if ($lastData) {
                        $now = new \DateTime();
                        $diff = $now->getTimestamp() - $lastData->getTimestamp()->getTimestamp();

                        if ($diff > 21600) { // 6 hours
                            $hours = floor($diff / 3600);
                            $items[] = [
                                'type' => 'warning',
                                'message' => $device->getSerialNumber() . ' offline (' . $hours . 'h ago)',
                                'config' => $config,
                                'device' => $device,
                            ];
                        }
                    }
                }
            }
        }

        return $items;
    }
}
