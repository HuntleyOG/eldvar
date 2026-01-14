import { Injectable, NotFoundException, BadRequestException } from '@nestjs/common';
import { PrismaService } from '@/common/prisma/prisma.service';

@Injectable()
export class LocationsService {
  constructor(private prisma: PrismaService) {}

  async getAllLocations() {
    return await this.prisma.worldArea.findMany({
      orderBy: { name: 'asc' },
      select: {
        id: true,
        slug: true,
        name: true,
        shortBlurb: true,
        imagePath: true,
        loreText: true,
      },
    });
  }

  async getLocationBySlug(slug: string) {
    const location = await this.prisma.worldArea.findUnique({
      where: { slug },
      select: {
        id: true,
        slug: true,
        name: true,
        shortBlurb: true,
        imagePath: true,
        loreText: true,
      },
    });

    if (!location) {
      throw new NotFoundException('Location not found');
    }

    return location;
  }

  async getCurrentLocation(userId: number) {
    const user = await this.prisma.user.findUnique({
      where: { id: userId },
      select: { currentAreaCode: true },
    });

    if (!user) {
      throw new NotFoundException('User not found');
    }

    // If user has no location set, return the first area (default starting location)
    if (!user.currentAreaCode) {
      const defaultArea = await this.prisma.worldArea.findFirst({
        orderBy: { id: 'asc' },
      });

      if (defaultArea) {
        // Set default location for user
        await this.prisma.user.update({
          where: { id: userId },
          data: { currentAreaCode: defaultArea.slug },
        });
        return defaultArea;
      }

      throw new NotFoundException('No locations available');
    }

    return await this.getLocationBySlug(user.currentAreaCode);
  }

  async travelToLocation(userId: number, destinationSlug: string) {
    // Verify destination exists
    const destination = await this.getLocationBySlug(destinationSlug);

    // Get current location to check if already there
    const user = await this.prisma.user.findUnique({
      where: { id: userId },
      select: { currentAreaCode: true },
    });

    if (user?.currentAreaCode === destinationSlug) {
      throw new BadRequestException('You are already at this location');
    }

    // Update user's location
    await this.prisma.user.update({
      where: { id: userId },
      data: {
        currentAreaCode: destinationSlug,
        lastSeen: new Date(),
      },
    });

    return {
      message: `Successfully traveled to ${destination.name}`,
      location: destination,
    };
  }
}
